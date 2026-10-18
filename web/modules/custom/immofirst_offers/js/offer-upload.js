/**
 * @file
 * German client-side messages for the offer form's file upload.
 *
 * Core's file.js checks the extension in the browser and shows an English
 * message. For the offer form only, the same check is shown in German with
 * the allowed formats as "PDF, DOC, DOCX, JPG, JPEG und PNG"; every other
 * file field keeps core's behaviour. This is a convenience only — the
 * server enforces the same rules (OfferUploadGuard).
 */
(function ($, Drupal) {
  'use strict';

  var FORM_SELECTOR = '.webform-submission-offer-to-search-request-form';

  // Webform's "upload still in progress" confirm dialog on submit.
  window.drupalTranslations = window.drupalTranslations || {};
  window.drupalTranslations.strings = window.drupalTranslations.strings || {};
  var strings = window.drupalTranslations.strings[''] = window.drupalTranslations.strings[''] || {};
  strings['File upload in progress. Uploaded file may be lost.'] = strings['File upload in progress. Uploaded file may be lost.'] ||
    'Der Datei-Upload läuft noch. Die Datei geht möglicherweise verloren.';
  strings['Click OK to submit the form without finishing the file upload or cancel to return to form.'] = strings['Click OK to submit the form without finishing the file upload or cancel to return to form.'] ||
    'Klicken Sie auf OK, um das Formular ohne die Datei abzusenden, oder auf Abbrechen, um zum Formular zurückzukehren.';

  function formatList(extensions) {
    var list = extensions.split(/[,\s]+/).filter(Boolean).map(function (ext) {
      return ext.toUpperCase();
    });
    var last = list.pop();
    return list.length ? list.join(', ') + ' und ' + last : last;
  }

  // file.js binds Drupal.file.validateExtension when behaviors attach,
  // which happens after this script has run, so wrapping it here is enough.
  var coreValidateExtension = Drupal.file.validateExtension;
  Drupal.file.validateExtension = function (event) {
    if (!$(this).closest(FORM_SELECTOR).length) {
      return coreValidateExtension.call(this, event);
    }
    event.preventDefault();
    $('.file-upload-js-error').remove();

    var extensions = event.data.extensions || '';
    var pattern = extensions.replace(/,\s*/g, '|');
    if (pattern.length > 1 && this.value.length > 0 && !new RegExp('\\.(' + pattern + ')$', 'i').test(this.value)) {
      var error = Drupal.t('Die ausgewählte Datei %filename kann nicht hochgeladen werden. Erlaubte Dateiformate: @formats.', {
        '%filename': this.value.replace('C:\\fakepath\\', ''),
        '@formats': formatList(extensions)
      });
      $(this)
        .closest('div.js-form-managed-file')
        .prepend('<div class="messages messages--error file-upload-js-error" aria-live="polite">' + error + '</div>');
      this.value = '';
      event.stopImmediatePropagation();
    }
  };
})(jQuery, Drupal);
