<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\Element\ManagedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Server-side pre-check for the offer form's file upload ("dateien").
 *
 * Runs as the element's #value_callback, i.e. before core's
 * ManagedFile::valueCallback() moves the upload into private storage. A
 * rejected file is removed from the request, so core never stores it, and
 * the user gets one German error instead of core's English messages.
 *
 * Core's own upload validators (FileExtension, FileSizeLimit,
 * FileNameLength, set by Webform from the element config) stay in place
 * and still run for everything that passes here; this class only adds:
 *   - German messages for the same rules (allowed formats and max size are
 *     read from those validators, so the Webform UI stays the one place to
 *     configure them),
 *   - a content check: the detected MIME type must fit the extension
 *     (e.g. no renamed .exe/.html as ".pdf"),
 *   - a per-IP flood limit on upload attempts, because every anonymous
 *     upload is written to disk before the offer is even submitted.
 *
 * Not a virus scanner: files that pass are not checked for malware.
 */
final class OfferUploadGuard {

  /**
   * Flood event name and limits for upload attempts per client IP.
   */
  private const FLOOD_EVENT = 'immofirst_offers.offer_upload';
  private const FLOOD_THRESHOLD = 20;
  private const FLOOD_WINDOW = 3600;

  /**
   * MIME types finfo may report for each supported extension.
   *
   * Deliberately generous: older libmagic versions report Office files as
   * generic OLE/ZIP containers. Extensions not listed here (if more are
   * enabled in the Webform UI later) skip the content check.
   */
  private const MIME_TYPES = [
    'pdf' => ['application/pdf', 'application/x-pdf'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'jpg' => ['image/jpeg', 'image/pjpeg'],
    'jpeg' => ['image/jpeg', 'image/pjpeg'],
    'png' => ['image/png'],
  ];

  /**
   * Adds the guard to the offer form's upload element (hook_form_alter).
   */
  public static function protect(array &$element): void {
    $element['#immofirst_offers_value_callback'] = $element['#value_callback'] ?? [ManagedFile::class, 'valueCallback'];
    $element['#value_callback'] = [self::class, 'valueCallback'];
    $element['#attached']['library'][] = 'immofirst_offers/offer-upload';
  }

  /**
   * #value_callback: rejects invalid uploads, then defers to core.
   */
  public static function valueCallback(array &$element, $input, FormStateInterface $form_state): mixed {
    $request = \Drupal::request();
    $upload_name = implode('_', $element['#parents']);
    $all_files = $request->files->get('files', []);
    $uploads = array_filter(is_array($all_files[$upload_name] ?? NULL) ? $all_files[$upload_name] : [$all_files[$upload_name] ?? NULL]);

    // The value callback can run more than once per request (form rebuild);
    // check and count each upload only once.
    $checked = &drupal_static(__METHOD__, []);
    if ($input !== FALSE && $uploads && !isset($checked[$upload_name])) {
      $checked[$upload_name] = TRUE;
      $error = self::check($element, $uploads);
      if ($error !== NULL) {
        $form_state->setError($element, $error);
        unset($all_files[$upload_name]);
        $request->files->set('files', $all_files);
      }
    }

    $callback = $element['#immofirst_offers_value_callback'] ?? [ManagedFile::class, 'valueCallback'];
    return $callback($element, $input, $form_state);
  }

  /**
   * Returns a German error for the first invalid upload, or NULL.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile[] $uploads
   */
  private static function check(array $element, array $uploads): ?TranslatableMarkup {
    $flood = \Drupal::flood();
    if (!$flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      return t('Sie haben in kurzer Zeit zu viele Dateien hochgeladen. Bitte versuchen Sie es später erneut.');
    }
    $flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    $validators = $element['#upload_validators'] ?? [];
    $extensions = array_filter(explode(' ', strtolower((string) ($validators['FileExtension']['extensions'] ?? ''))));
    $max_size = (int) ($validators['FileSizeLimit']['fileLimit'] ?? 0);
    $max_length = (int) ($validators['FileNameLength']['maxLength'] ?? 0);
    $formats = self::formatList($extensions);

    foreach ($uploads as $upload) {
      if (!$upload instanceof UploadedFile) {
        continue;
      }
      $filename = $upload->getClientOriginalName();
      $args = ['%filename' => $filename, '@formats' => $formats];

      if (!$upload->isValid()) {
        return in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)
          ? t('Die ausgewählte Datei %filename ist zu groß. Die maximale Dateigröße beträgt @max.', ['%filename' => $filename, '@max' => self::formatSize($max_size ?: (int) $upload->getMaxFilesize())])
          : t('Die ausgewählte Datei %filename konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.', ['%filename' => $filename]);
      }

      $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
      if ($extensions && !in_array($extension, $extensions, TRUE)) {
        return t('Die ausgewählte Datei %filename kann nicht hochgeladen werden. Erlaubte Dateiformate: @formats.', $args);
      }

      $size = (int) $upload->getSize();
      if ($size === 0 || !is_readable($upload->getRealPath())) {
        return t('Die ausgewählte Datei %filename ist leer oder kann nicht gelesen werden.', ['%filename' => $filename]);
      }
      if ($max_size && $size > $max_size) {
        return t('Die ausgewählte Datei %filename ist zu groß (@size). Die maximale Dateigröße beträgt @max.', [
          '%filename' => $filename,
          '@size' => self::formatSize($size),
          '@max' => self::formatSize($max_size),
        ]);
      }
      if ($max_length && mb_strlen($filename) > $max_length) {
        return t('Der Dateiname von %filename ist zu lang (maximal @max Zeichen). Bitte benennen Sie die Datei um und versuchen Sie es erneut.', ['%filename' => $filename, '@max' => $max_length]);
      }

      if (isset(self::MIME_TYPES[$extension])) {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        if (!in_array($mime, self::MIME_TYPES[$extension], TRUE)) {
          \Drupal::logger('immofirst_offers')->notice('Offer upload %filename rejected: content type %mime does not match the extension.', ['%filename' => $filename, '%mime' => $mime]);
          return t('Die ausgewählte Datei %filename kann nicht hochgeladen werden, da ihr Inhalt nicht zum Dateiformat passt. Erlaubte Dateiformate: @formats.', $args);
        }
      }
    }
    return NULL;
  }

  /**
   * German file size: "5 MB", "2,4 MB", "512 KB".
   */
  private static function formatSize(int $bytes): string {
    if ($bytes >= 1048576) {
      return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', '.'), '0'), ',') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
  }

  /**
   * "PDF, DOC, DOCX, JPG, JPEG und PNG".
   *
   * @param string[] $extensions
   */
  private static function formatList(array $extensions): string {
    $extensions = array_map('strtoupper', array_values($extensions));
    $last = array_pop($extensions);
    return $extensions ? implode(', ', $extensions) . ' und ' . $last : (string) $last;
  }

}
