<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\immofirst_search_request\Service\SearchCriteriaData;
use Drupal\immofirst_search_request\Service\SearchCriteriaTermRepository;
use Drupal\immofirst_search_request\Service\SearchRequestNodeCreator;
use Drupal\immofirst_search_request\Service\SearchRequestSession;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The 5-step "Suchauftrag erstellen" AJAX wizard.
 *
 * Step 0 (the overview/landing page built here) is shown until the
 * visitor clicks "Jetzt kostenlos starten"; from then on the wizard
 * behaves exactly as Max's design shows it. Steps: 1 "Was suchen
 * Sie?" (Gesuchsart + Objektart + Standort, all one screen per the
 * design), 2 "Ihre Anforderungen", 3 "Zusätzliche Kriterien", 4
 * "Ihre Kontaktdaten", 5 "Suchauftrag erstellt!" (the success screen
 * — not a form; no fields, no Weiter/Zurück actions). Only the
 * CURRENT step's elements are ever built into the form array — this
 * keeps validateForm() automatically scoped to the visible step
 * (Drupal only validates elements that actually exist in $form)
 * without extra bookkeeping.
 *
 * STEP-NUMBERING / DATA-BUCKETING NOTE: SearchRequestNodeCreator and
 * SearchRequestSession (both untouched, per spec) predate this
 * layout and still expect wizard data bucketed as step1=request_type,
 * step2=property_type, step3=location/radius/rooms/area/price/baujahr
 * (plus, since Step 2 became conditional on Objektart: land_size,
 * usage, parking_type, vehicle_type, commercial_type — new keys added
 * to the SAME 'step3' bucket, nothing renamed), step4=criteria/notes,
 * step5=contact. Since those bucket names are baked into
 * SearchRequestNodeCreator::createFromWizardData() (a file this
 * refactor must not touch), persistCurrentStep() below maps *this*
 * class's visual steps (1-5, per Max's design) onto those same bucket
 * names — visual Step 1 alone writes into three of them (step1,
 * step2, and step3's location/radius), visual Step 2 writes the rest
 * of step3 (exactly which keys depends on the selected Gesuchsart +
 * Objektart — see step2FieldsForSelection()), visual Step 3 writes
 * step4, and visual Step 4 writes step5. No service, TempStore, or
 * node-creation code changes — only how this class buckets values it
 * already collects.
 *
 * VISUAL STEP 5 / SUCCESS SCREEN NOTE: the previous revision of this
 * class redirected to the separate /suchauftrag-erstellen/danke route
 * (ThankYouController + search-request-thank-you.html.twig) after the
 * final submit. Per this refactor's FIX 7, the success state is now
 * visual Step 5 of the wizard itself — shown in place via the same
 * AJAX wrapper, no redirect. The /danke route, its controller and its
 * template are untouched and still reachable directly, but the normal
 * in-wizard flow no longer navigates to it.
 */
final class SearchRequestWizardForm extends FormBase {

  private const TOTAL_STEPS = 5;

  /**
   * @var string[]
   */
  private const STEP_TITLES = [
    1 => 'Was suchen Sie?',
    2 => 'Ihre Anforderungen',
    3 => 'Zusätzliche Kriterien',
    4 => 'Ihre Kontaktdaten',
    5 => 'Suchauftrag erstellt!',
  ];

  /**
   * Subtitles shown under each step's H1, per Max's design.
   *
   * Step 5 (success) is not in this list: it renders its own
   * description as part of buildStep5()'s custom markup instead of
   * the standard title+subtitle header — see buildForm()/the twig
   * template's `current_step != total_steps` check.
   *
   * @var string[]
   */
  private const STEP_SUBTITLES = [
    1 => 'Bitte wählen Sie aus, wonach Sie suchen.',
    2 => 'Legen Sie Ihre wichtigsten Kriterien fest.',
    3 => 'Wählen Sie weitere Optionen aus (optional).',
    4 => 'Bitte geben Sie Ihre Kontaktdaten an.',
  ];

  /**
   * UI label -> stored value, for Step 2 property type cards.
   *
   * 'commercial' (Gewerbe) is a NEW property type, added per this
   * Step-2-conditional-logic change. IMPORTANT: the 'suchauftrag'
   * node's field_property_icon (untouched — a content-model change is
   * outside this file's scope) currently only allows
   * home/apartment/plot/garage. Selecting "Gewerbe" is fully
   * functional through the wizard (Step 1 card, Step 2's conditional
   * fields, TempStore), but SearchRequestNodeCreator's final node save
   * will reject 'commercial' until someone adds it to that field's
   * allowed values list in the content model. Flagging this clearly
   * rather than silently shipping a card that fails at submission.
   *
   * @var array<string, string>
   */
  private const PROPERTY_TYPES = [
    'apartment' => 'Wohnung',
    'house' => 'Haus',
    'land' => 'Grundstück',
    'garage' => 'Stellplatz / Garage',
    'commercial' => 'Gewerbe',
  ];

  /**
   * Search-radius options for Step 2's "Umkreis" field.
   *
   * @var array<string, string>
   */
  private const RADIUS_OPTIONS = [
    '5' => '5 km',
    '10' => '10 km',
    '15' => '15 km',
    '25' => '25 km',
    '50' => '50 km',
    '100' => '100 km',
  ];

  /**
   * "Baujahr" range options for Step 2 (Wohnung/Haus only).
   *
   * @var array<string, string>
   */
  private const BAUJAHR_OPTIONS = [
    '2010_heute' => '2010 – heute',
    '1970_2010' => '1970 – 2010',
    'bis_1970' => 'bis ca. 1970',
  ];

  /**
   * "Nutzung" options for Step 2 (Grundstück only).
   *
   * @var array<string, string>
   */
  private const USAGE_OPTIONS = [
    'einfamilienhaus' => 'Einfamilienhaus',
    'mehrfamilienhaus' => 'Mehrfamilienhaus',
    'gewerbe' => 'Gewerbe',
    'sonstiges' => 'Sonstiges',
  ];

  /**
   * "Art des Stellplatzes" options for Step 2 (Garage only).
   *
   * @var array<string, string>
   */
  private const PARKING_TYPE_OPTIONS = [
    'aussenstellplatz' => 'Außenstellplatz',
    'carport' => 'Carport',
    'garage' => 'Garage',
    'tiefgaragenstellplatz' => 'Tiefgaragenstellplatz',
    'duplex_doppelparker' => 'Duplex / Doppelparker',
  ];

  /**
   * "Fahrzeugtyp" options for Step 2 (Garage only).
   *
   * @var array<string, string>
   */
  private const VEHICLE_TYPE_OPTIONS = [
    'kleinwagen' => 'Kleinwagen',
    'mittelklasse' => 'Mittelklasse',
    'suv' => 'SUV',
    'transporter' => 'Transporter',
    'wohnwagen' => 'Wohnwagen',
    'wohnmobil' => 'Wohnmobil',
  ];

  /**
   * "Art der Gewerbeimmobilie" options for Step 2 (Gewerbe only).
   *
   * @var array<string, string>
   */
  private const COMMERCIAL_TYPE_OPTIONS = [
    'buero_praxis' => 'Büro & Praxis',
    'handel_gastronomie' => 'Handel & Gastronomie',
    'lager_produktion' => 'Lager & Produktion',
    'industrie' => 'Industrie',
    'land_forstwirtschaft' => 'Land- & Forstwirtschaft',
    'grundstuecke_flaechen' => 'Grundstücke & Flächen',
  ];

  /**
   * Which Step 2 fields appear for each Objektart, and in what order.
   *
   * Shared between Kaufen and Mieten — the price field's label changes
   * per combination (see priceFieldLabel()) and, for Mieten +
   * Grundstück only, disappears entirely; both are handled as small
   * exceptions in step2FieldsForSelection() rather than a second,
   * near-duplicate table, since that's the only place a field actually
   * disappears rather than just getting relabeled.
   *
   * @var array<string, string[]>
   */
  private const STEP2_FIELDS_BY_PROPERTY_TYPE = [
    'apartment' => ['area', 'rooms', 'price', 'baujahr'],
    'house' => ['area', 'rooms', 'price', 'baujahr', 'land_size'],
    'land' => ['land_size', 'price', 'usage'],
    'garage' => ['parking_type', 'vehicle_type', 'price'],
    'commercial' => ['commercial_type', 'area', 'price'],
  ];

  /**
   * Calling-code options for Step 4's "WhatsApp-Nummer" field.
   *
   * @var array<string, string>
   */
  private const PHONE_COUNTRY_OPTIONS = [
    '+49' => '🇩🇪 +49',
    '+43' => '🇦🇹 +43',
    '+41' => '🇨🇭 +41',
  ];

  /**
   * The wizard session service.
   *
   * Not readonly: the Form API caches this form object and unserializes
   * it fresh on every AJAX request without calling the constructor
   * (or create()), so this property must remain assignable from
   * __wakeup() to be rehydrated after unserialization.
   */
  protected SearchRequestSession $session;

  /**
   * The node creator service.
   *
   * See the note on $session above; the same applies here.
   */
  protected SearchRequestNodeCreator $nodeCreator;

  /**
   * Loads Step 3's checkbox options from the search_criteria taxonomy.
   *
   * See the note on $session above; the same applies here.
   */
  protected SearchCriteriaTermRepository $criteriaTermRepository;

  public function __construct(
    SearchRequestSession $session,
    SearchRequestNodeCreator $nodeCreator,
    SearchCriteriaTermRepository $criteriaTermRepository,
  ) {
    $this->session = $session;
    $this->nodeCreator = $nodeCreator;
    $this->criteriaTermRepository = $criteriaTermRepository;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('immofirst_search_request.session'),
      $container->get('immofirst_search_request.node_creator'),
      $container->get('immofirst_search_request.criteria_term_repository'),
    );
  }

  /**
   * Rehydrates injected services after the cached form is unserialized.
   *
   * The Form API stores this form object in the form cache between AJAX
   * requests and restores it via PHP's native unserialize(), which
   * never runs the constructor (or ::create()). That leaves $session,
   * $nodeCreator, and $criteriaTermRepository — all typed properties
   * with no default value — uninitialized on the restored object,
   * causing a fatal "must not be accessed before initialization" error
   * the first time they're used (e.g. in persistCurrentStep()).
   * __wakeup() is PHP's hook for this exact situation: it runs
   * immediately after unserialize() rebuilds the object, so
   * re-fetching all three services from the container here restores
   * them before any other method can run.
   */
  public function __wakeup(): void {
    $container = \Drupal::getContainer();
    $this->session = $container->get('immofirst_search_request.session');
    $this->nodeCreator = $container->get('immofirst_search_request.node_creator');
    $this->criteriaTermRepository = $container->get('immofirst_search_request.criteria_term_repository');
  }

  public function getFormId(): string {
    return 'immofirst_search_request_wizard';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $form_state->get('step') ?? $this->session->getCurrentStep();
    $form_state->set('step', $step);

    $form['#tree'] = TRUE;
    $form['#attributes']['id'] = 'immofirst-wizard-form';
    $form['#attributes']['class'][] = 'wizard';
    $form['#theme'] = ['search_request_wizard'];
    $form['#attached']['library'][] = 'immofirst_search_request/wizard';

    // Step 0: overview/landing page. Tracked via the existing generic
    // getStepData()/setStepData() API under an 'overview' key — no
    // service changes. Once started, this is permanently skipped until
    // SearchRequestSession::clear() runs again (final submit).
    //
    // TEMP (dev/testing convenience): visiting the wizard URL with
    // ?wizard_reset=1 forces that clear() early, so Step 0 shows again
    // even if an earlier test run already set 'overview.started' in
    // this browser's PrivateTempStore session (the most common reason
    // Step 0 appears to be "missing" — it's not gone, the wizard is
    // just resuming where a previous visit left off, which is the
    // correct behavior once QA is done). Safe to delete this block
    // once testing wraps up.
    if (\Drupal::request()->query->get('wizard_reset') === '1') {
      $this->session->clear();
      $form_state->set('step', 1);
    }

    $wizardStarted = (bool) ($this->session->getStepData('overview')['started'] ?? FALSE);

    if (!$wizardStarted) {
      $form['#attributes']['class'][] = 'wizard--overview';
      $form['overview'] = $this->buildOverview();

      return $form;
    }

    $form['#immofirst_step'] = $step;
    $form['#immofirst_total_steps'] = self::TOTAL_STEPS;
    $form['#immofirst_step_titles'] = self::STEP_TITLES;
    $form['#immofirst_step_subtitles'] = self::STEP_SUBTITLES;

    $form['step_content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard__step-content'], 'data-wizard-step' => (string) $step],
    ];

    // Steps 1-4 get the standard "<title> / <subtitle>" header (the H1
    // itself is rendered by the twig template from #immofirst_step_titles;
    // this just adds the subtitle line directly under it — see the
    // class docblock's note on why this lives in step_content rather
    // than a new preprocess variable). Step 5 (success) builds its own
    // full header as part of its custom markup instead.
    if ($step >= 1 && $step <= 4) {
      $form['step_content']['_subtitle'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => self::STEP_SUBTITLES[$step] ?? '',
        '#attributes' => ['class' => ['wizard__step-subtitle']],
      ];
    }

    match ($step) {
      1 => $this->buildStep1($form, $form_state),
      2 => $this->buildStep2($form, $form_state),
      3 => $this->buildStep3($form, $form_state),
      4 => $this->buildStep4($form, $form_state),
      5 => $this->buildStep5($form, $form_state),
      default => $this->buildStep1($form, $form_state),
    };

    // Step 5 (success) has no Zurück/Weiter/Suchauftrag-anlegen actions
    // at all — it's a terminal display state, per the design (no
    // buttons shown on that screen). Steps 1-3 get Weiter; Step 4 (the
    // last INPUT step) gets the final "Suchauftrag anlegen" submit
    // instead of Weiter.
    if ($step >= 1 && $step <= 4) {
      $form['actions'] = [
        '#type' => 'actions',
        '#attributes' => ['class' => ['wizard__actions']],
      ];

      if ($step > 1) {
        $form['actions']['back'] = [
          '#type' => 'submit',
          '#value' => $this->t('Zurück'),
          '#name' => 'wizard_back',
          '#submit' => ['::submitBack'],
          '#limit_validation_errors' => [],
          '#attributes' => ['class' => ['btn', 'btn--outline', 'wizard__btn-back']],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'immofirst-wizard-form',
            'effect' => 'fade',
          ],
        ];
      }

      if ($step < 4) {
        $form['actions']['next'] = [
          '#type' => 'submit',
          '#value' => $this->t('Weiter'),
          '#name' => 'wizard_next',
          '#submit' => ['::submitNext'],
          '#attributes' => ['class' => ['btn', 'btn--primary', 'wizard__btn-next'], 'data-wizard-next' => 'true'],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'immofirst-wizard-form',
            'effect' => 'fade',
          ],
        ];
      }
      else {
        $form['actions']['finish'] = [
          '#type' => 'submit',
          '#value' => $this->t('Suchauftrag anlegen'),
          '#name' => 'wizard_finish',
          '#submit' => ['::submitFinish'],
          '#attributes' => ['class' => ['btn', 'btn--primary', 'wizard__btn-finish']],
          // Reuses ::ajaxRefresh: the success screen now renders in
          // place (visual Step 5) instead of redirecting, so there is
          // nothing left for a dedicated finish callback to do beyond
          // what ajaxRefresh already does (re-render the wrapper).
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'immofirst-wizard-form',
            'effect' => 'fade',
          ],
        ];

        // "Lock icon + privacy text" below the button, per the design
        // — not part of the actions flex row, so it's a sibling form
        // key instead. `form|without('step_content', 'actions')` in
        // the twig template already renders any remaining top-level
        // form children (this one included) right after the actions
        // block, so no template change is needed for it to appear
        // exactly where the design shows it.
        $form['step_footer'] = [
          '#markup' => Markup::create($this->buildPrivacyNote()),
        ];
      }
    }

    return $form;
  }

  /* ==========================================================
   * STEP 0: OVERVIEW / LANDING PAGE
   * ========================================================== */

  /**
   * Builds the Step 0 overview shown before the wizard starts.
   *
   * The theme already renders the global header, navigation and
   * footer, so this contains no branding/logo/topbar of its own — it
   * starts directly with the hero. The CTA is a real Drupal submit
   * element (reusing the existing ::ajaxRefresh callback and wrapper),
   * so clicking it drives the transition to Step 1 through the same
   * AJAX pipeline as the "Weiter"/"Zurück" buttons.
   *
   * @return array
   *   A render array for the Step 0 overview.
   */
  private function buildOverview(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['immofirst-overview']],
      'hero' => [
        '#markup' => Markup::create($this->buildOverviewHero()),
      ],
      'benefits' => [
        '#markup' => Markup::create($this->buildOverviewBenefits()),
      ],
      'cta' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['immofirst-overview__cta']],
        'button' => [
          '#type' => 'submit',
          '#value' => $this->t('Jetzt kostenlos starten'),
          '#name' => 'start_wizard',
          '#attributes' => ['class' => ['immofirst-overview__cta-button']],
          '#submit' => ['::startWizard'],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'immofirst-wizard-form',
            'effect' => 'fade',
          ],
        ],
        'trust' => [
          '#markup' => Markup::create($this->buildOverviewTrustBadges()),
        ],
      ],
      'process' => [
        '#markup' => Markup::create($this->buildOverviewProcess()),
      ],
      'panel' => [
        '#markup' => Markup::create($this->buildOverviewPanel()),
      ],
    ];
  }

  /**
   * Builds the hero markup (icon, heading, subtitle).
   *
   * @return string
   *   Raw HTML for the hero section.
   */
  private function buildOverviewHero(): string {
    $line1 = $this->t('Finden Sie Ihre neue');
    $accent = $this->t('Wunschimmobilie,');
    $line3 = $this->t('bevor es andere sehen.');
    $subtitle = $this->t('Erhalten Sie exklusive Immobilien, noch bevor sie öffentlich auf den großen Plattformen erscheinen.');

    return <<<HTML
<section class="immofirst-overview__hero" aria-labelledby="immofirst-overview-heading">
  <div class="immofirst-overview__hero-icon">{$this->overviewIcon('house-heart', 32)}</div>
  <h1 id="immofirst-overview-heading" class="immofirst-overview__hero-title">
    {$line1}<br>
    <span class="immofirst-overview__hero-title-accent">{$accent}</span><br>
    {$line3}
  </h1>
  <p class="immofirst-overview__hero-subtitle">{$subtitle}</p>
</section>
HTML;
  }

  /**
   * Builds the three stacked benefit cards.
   *
   * @return string
   *   Raw HTML for the benefits section.
   */
  private function buildOverviewBenefits(): string {
    $heading = $this->t('Vorteile auf einen Blick');

    $card1Title = $this->t('In nur zwei Minuten erstellt');
    $card1Text = $this->t('Schnell, einfach, ohne Registrierung.');

    $card2Title = $this->t('Ihre Daten bleiben geschützt');
    $card2Text = $this->t('Ihre Telefonnummer, ID und E-Mail werden nicht veröffentlicht. Sie entscheiden selbst, wann Sie Kontakt freigeben möchten.');

    $card3Title = $this->t('Ohne Registrierung');
    $card3Text = $this->t('Kein Konto, kein Passwort, einfach loslegen.');

    $checkBadge = $this->overviewIcon('check', 12);

    return <<<HTML
<section class="immofirst-overview__benefits" aria-label="{$heading}">
  <h2 class="visually-hidden">{$heading}</h2>
  <div class="immofirst-overview__benefit">
    <div class="immofirst-overview__benefit-icon">
      {$this->overviewIcon('clock', 24)}
      <span class="immofirst-overview__benefit-icon-badge">{$checkBadge}</span>
    </div>
    <div class="immofirst-overview__benefit-content">
      <p class="immofirst-overview__benefit-title">{$card1Title}</p>
      <p class="immofirst-overview__benefit-text">{$card1Text}</p>
    </div>
  </div>
  <div class="immofirst-overview__benefit">
    <div class="immofirst-overview__benefit-icon">
      {$this->overviewIcon('shield-check', 24)}
      <span class="immofirst-overview__benefit-icon-badge">{$checkBadge}</span>
    </div>
    <div class="immofirst-overview__benefit-content">
      <p class="immofirst-overview__benefit-title">{$card2Title}</p>
      <p class="immofirst-overview__benefit-text">{$card2Text}</p>
    </div>
  </div>
  <div class="immofirst-overview__benefit">
    <div class="immofirst-overview__benefit-icon">
      {$this->overviewIcon('user-check', 24)}
      <span class="immofirst-overview__benefit-icon-badge">{$checkBadge}</span>
    </div>
    <div class="immofirst-overview__benefit-content">
      <p class="immofirst-overview__benefit-title">{$card3Title}</p>
      <p class="immofirst-overview__benefit-text">{$card3Text}</p>
    </div>
  </div>
</section>
HTML;
  }

  /**
   * Builds the three inline trust badges shown below the primary CTA.
   *
   * @return string
   *   Raw HTML for the trust badges.
   */
  private function buildOverviewTrustBadges(): string {
    $item1 = $this->t('Kostenlos');
    $item2 = $this->t('Unverbindlich');
    $item3 = $this->t('Jederzeit anpassbar');
    $check = $this->overviewIcon('check-bold', 14);

    return <<<HTML
<ul class="immofirst-overview__trust">
  <li class="immofirst-overview__trust-item">{$check}<span>{$item1}</span></li>
  <li class="immofirst-overview__trust-item">{$check}<span>{$item2}</span></li>
  <li class="immofirst-overview__trust-item">{$check}<span>{$item3}</span></li>
</ul>
HTML;
  }

  /**
   * Builds the "how it works" process section.
   *
   * @return string
   *   Raw HTML for the process section.
   */
  private function buildOverviewProcess(): string {
    $heading = $this->t('So einfach funktioniert es');

    $step1Title = $this->t('Wünsche eingeben');
    $step1Text = $this->t('Beschreiben Sie kurz Ihre Wunschimmobilie.');

    $step2Title = $this->t('Angebote erhalten');
    $step2Text = $this->t('Passende Immobilien von Verkäufern & Maklern erhalten.');

    $step3Title = $this->t('Kontakt bei Interesse');
    $step3Text = $this->t('Sie entscheiden, wann Sie den Kontakt freigeben möchten.');

    return <<<HTML
<section class="immofirst-overview__process" aria-labelledby="immofirst-overview-process-heading">
  <h2 id="immofirst-overview-process-heading" class="immofirst-overview__process-title">{$heading}</h2>
  <ol class="immofirst-overview__process-list">
    <li class="immofirst-overview__process-item">
      <span class="immofirst-overview__process-icon-wrap">
        {$this->overviewIcon('clipboard-pen', 28)}
        <span class="immofirst-overview__process-badge" aria-hidden="true">1</span>
      </span>
      <h3 class="immofirst-overview__process-item-title">{$step1Title}</h3>
      <p class="immofirst-overview__process-item-text">{$step1Text}</p>
    </li>
    <li class="immofirst-overview__process-item">
      <span class="immofirst-overview__process-icon-wrap">
        {$this->overviewIcon('envelope', 28)}
        <span class="immofirst-overview__process-badge" aria-hidden="true">2</span>
      </span>
      <h3 class="immofirst-overview__process-item-title">{$step2Title}</h3>
      <p class="immofirst-overview__process-item-text">{$step2Text}</p>
    </li>
    <li class="immofirst-overview__process-item">
      <span class="immofirst-overview__process-icon-wrap">
        {$this->overviewIcon('user-check', 28)}
        <span class="immofirst-overview__process-badge" aria-hidden="true">3</span>
      </span>
      <h3 class="immofirst-overview__process-item-title">{$step3Title}</h3>
      <p class="immofirst-overview__process-item-text">{$step3Text}</p>
    </li>
  </ol>
</section>
HTML;
  }

  /**
   * Builds the bottom rounded green trust panel.
   *
   * @return string
   *   Raw HTML for the bottom trust panel.
   */
  private function buildOverviewPanel(): string {
    $heading = $this->t('Vertrauen & Sicherheit');
    $title = $this->t('Sie behalten die Kontrolle.');
    $text = $this->t('Erst wenn ein Angebot zusagt, entscheiden Sie, ob Sie Kontakt zum Anbieter freigeben möchten.');

    return <<<HTML
<section class="immofirst-overview__panel" aria-label="{$heading}">
  <h2 class="visually-hidden">{$heading}</h2>
  <div class="immofirst-overview__panel-icon">{$this->overviewIcon('lock', 24)}</div>
  <div class="immofirst-overview__panel-content">
    <p class="immofirst-overview__panel-title">{$title}</p>
    <p class="immofirst-overview__panel-text">{$text}</p>
  </div>
</section>
HTML;
  }

  /**
   * Returns a small inline SVG icon used throughout the overview page,
   * the Step 5 success screen, and (icons named below the "--- Step 3
   * icons ---" marker) as the Step 3 "Zusätzliche Kriterien"
   * group-card headers.
   *
   * All icons are purely decorative (their meaning is always carried by
   * adjacent visible text), so each is marked aria-hidden="true" and
   * focusable="false".
   *
   * @param string $name
   *   The icon identifier.
   * @param int $size
   *   The icon's width/height in pixels.
   *
   * @return string
   *   Raw inline SVG markup.
   */
  private function overviewIcon(string $name, int $size = 24): string {
    $attrs = "width=\"{$size}\" height=\"{$size}\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"";

    return match ($name) {
      'house-heart' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <path d="M3.5 11.5 12 4l8.5 7.5"/>
  <path d="M6 10.5V20a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9.5"/>
  <path d="M12 17.2s-2.6-1.6-2.6-3.4a1.6 1.6 0 0 1 2.6-1.2 1.6 1.6 0 0 1 2.6 1.2c0 1.8-2.6 3.4-2.6 3.4Z"/>
</svg>
SVG,
      'clock' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <circle cx="12" cy="12" r="8.5"/>
  <path d="M12 7.5V12l3 2"/>
</svg>
SVG,
      'shield-check' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <path d="M12 3.5 19 6v5.5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-2.5Z"/>
  <path d="M9 12.2l2 2 4-4"/>
</svg>
SVG,
      'user-check' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <circle cx="10.5" cy="8" r="3.3"/>
  <path d="M4.5 19c0-3.3 2.7-5.5 6-5.5 1 0 1.9.2 2.7.6"/>
  <path d="M15.5 16.2l1.8 1.8 3-3.2"/>
</svg>
SVG,
      'clipboard-pen' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <rect x="5.5" y="4.5" width="10" height="15" rx="1.4"/>
  <path d="M9 4.2h2.8a.9.9 0 0 1 .9.9v.6H8.1v-.6a.9.9 0 0 1 .9-.9Z"/>
  <path d="M8.5 12.2l3.6-3.6 1.6 1.6-3.6 3.6H8.5v-1.6Z"/>
</svg>
SVG,
      'envelope' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <rect x="4" y="6" width="16" height="12" rx="1.6"/>
  <path d="M4.5 6.8 12 12.5l7.5-5.7"/>
</svg>
SVG,
      'lock' => <<<SVG
<svg {$attrs} stroke-width="1.6">
  <rect x="5.5" y="10.5" width="13" height="9" rx="1.6"/>
  <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>
  <circle cx="12" cy="14.7" r="1.3" fill="currentColor" stroke="none"/>
</svg>
SVG,
      'check' => <<<SVG
<svg {$attrs} stroke-width="2.4">
  <path d="M4 12.5l5 5L20 6.5"/>
</svg>
SVG,
      'check-bold' => <<<SVG
<svg {$attrs} stroke-width="2.6">
  <path d="M4 12.5l5 5L20 6.5"/>
</svg>
SVG,
      /* --- Step 5 (success) icons --- */
      'pencil' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M14.5 4.5 19.5 9.5 8 21H3v-5Z"/>
  <path d="M12.5 6.5 17.5 11.5"/>
</svg>
SVG,
      'copy' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <rect x="8.5" y="8.5" width="11" height="11" rx="1.6"/>
  <path d="M15.5 8.5V6.1a1.6 1.6 0 0 0-1.6-1.6H6.1A1.6 1.6 0 0 0 4.5 6.1v7.8a1.6 1.6 0 0 0 1.6 1.6h2.4"/>
</svg>
SVG,
      /* --- Step 3 icons: "Zusätzliche Kriterien" group-card headers --- */
      'home' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M3.5 10.5 12 3.5l8.5 7"/>
  <path d="M5.5 9.5V19a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V9.5"/>
</svg>
SVG,
      'building' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <rect x="5" y="3.5" width="14" height="17" rx="1.2"/>
  <path d="M8.5 7.5h1.6M14 7.5h1.6M8.5 11h1.6M14 11h1.6M8.5 14.5h1.6M14 14.5h1.6"/>
</svg>
SVG,
      'car' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M4 15.5 5.6 9.8a2 2 0 0 1 1.9-1.4h9a2 2 0 0 1 1.9 1.4l1.6 5.7"/>
  <rect x="3.5" y="15.5" width="17" height="4" rx="1.2"/>
  <circle cx="7.5" cy="19.5" r="1.2"/>
  <circle cx="16.5" cy="19.5" r="1.2"/>
</svg>
SVG,
      'layers' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M12 3.5 3.8 8 12 12.5 20.2 8Z"/>
  <path d="M3.8 12.5 12 17l8.2-4.5"/>
  <path d="M3.8 17 12 21.5 20.2 17"/>
</svg>
SVG,
      'tree' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M12 21v-6.5"/>
  <path d="M12 14.5a5 5 0 1 0-4.4-7.4A4 4 0 0 0 8 14.5Z"/>
  <path d="M12 11.5a4.3 4.3 0 1 0 3.8-6.3 3.4 3.4 0 0 0-3.8 6.3Z"/>
</svg>
SVG,
      'list' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M8.5 6.5h11M8.5 12h11M8.5 17.5h11"/>
  <circle cx="4.5" cy="6.5" r="0.9" fill="currentColor" stroke="none"/>
  <circle cx="4.5" cy="12" r="0.9" fill="currentColor" stroke="none"/>
  <circle cx="4.5" cy="17.5" r="0.9" fill="currentColor" stroke="none"/>
</svg>
SVG,
      'map-pin' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 1 1 13 0c0 5.4-6.5 11-6.5 11Z"/>
  <circle cx="12" cy="10" r="2.3"/>
</svg>
SVG,
      'ruler' => <<<SVG
<svg {$attrs} stroke-width="1.7">
  <rect x="3.5" y="8.5" width="17" height="7" rx="1.2" transform="rotate(-8 12 12)"/>
  <path d="M7 9.5l.6 2M10.5 8.9l.6 2M14 8.3l.6 2M17.5 7.7l.6 2"/>
</svg>
SVG,
      /* Step 3 card-header chevron (open/collapsible indicator) — see
         criteriaGroupChevron(). */
      'chevron-down' => <<<SVG
<svg {$attrs} stroke-width="1.8">
  <path d="M5.5 8.5 12 15l6.5-6.5"/>
</svg>
SVG,
      default => '',
    };
  }

  /**
   * Submit handler for the overview's "Jetzt kostenlos starten" button.
   *
   * Marks the wizard as started (via the existing generic
   * getStepData()/setStepData() API) and sets the current step to 1.
   * The shared ::ajaxRefresh callback then re-renders the form, which
   * now takes the normal Step 1 branch — no page reload.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function startWizard(array &$form, FormStateInterface $form_state): void {
    $this->session->setStepData('overview', ['started' => TRUE]);
    $this->session->setCurrentStep(1);
    $form_state->set('step', 1);
    $form_state->setRebuild(TRUE);
  }

  /* ==========================================================
   * STEP BUILDERS
   * ========================================================== */

  /**
   * Step 1: Gesuchsart cards + Objektart cards + "Wo suchen Sie" location.
   *
   * Per the design, all three groups live on a single first screen.
   * They're still persisted into three separate session buckets
   * (step1/step2/step3) though, since SearchRequestNodeCreator
   * (untouched) reads request_type from $data['step1'], property_type
   * from $data['step2'], and location/radius from $data['step3'] —
   * see persistCurrentStep().
   */
  private function buildStep1(array &$form, FormStateInterface $form_state): void {
    $stored1 = $this->session->getStepData('step1');
    $stored2 = $this->session->getStepData('step2');
    $stored3 = $this->session->getStepData('step3');

    $form['step_content']['request_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Ich suche zum...'),
      // '#required' => TRUE, // TEMP: button validation disabled for now
      '#options' => [
        'kaufen' => $this->t('Kaufen'),
        'mieten' => $this->t('Mieten'),
      ],
      '#default_value' => $stored1['request_type'] ?? NULL,
      '#attributes' => ['class' => ['wizard-cards', 'wizard-cards--gesuchsart']],
    ];

    $options = [];
    foreach (self::PROPERTY_TYPES as $value => $label) {
      $options[$value] = $label;
    }

    $form['step_content']['property_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Was suchen Sie?'),
      // '#required' => TRUE, // TEMP: button validation disabled for now
      '#options' => $options,
      '#default_value' => $stored2['property_type'] ?? NULL,
      '#attributes' => ['class' => ['wizard-cards', 'wizard-cards--objektart']],
    ];

    $form['step_content']['location_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard__section']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Wo suchen Sie'),
        '#attributes' => ['class' => ['wizard__section-title']],
      ],
      'fields' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['wizard-location-row']],
        'location' => [
          '#type' => 'textfield',
          '#title' => $this->t('Ort oder PLZ'),
          // '#required' => TRUE, // TEMP: button validation disabled for now
          '#placeholder' => $this->t('z. B. München oder 80331'),
          '#default_value' => $stored3['location'] ?? '',
          '#autocomplete_route_name' => 'immofirst_search_request.location_autocomplete',
          '#attributes' => ['class' => ['wizard-input', 'wizard-input--location']],
        ],
        'radius' => [
          '#type' => 'select',
          '#title' => $this->t('Umkreis'),
          '#options' => self::RADIUS_OPTIONS,
          '#default_value' => $stored3['radius'] ?? '25',
          '#attributes' => ['class' => ['wizard-input', 'wizard-select', 'wizard-input--radius']],
        ],
      ],
    ];
  }

  /**
   * Step 2: "Ihre Anforderungen" — fully conditional on Step 1's
   * Gesuchsart (request_type) and Objektart (property_type).
   *
   * Only the fields relevant to that exact combination are added to
   * the render array at all (see step2FieldsForSelection() /
   * STEP2_FIELDS_BY_PROPERTY_TYPE) — nothing is built-then-hidden via
   * CSS, so the rendered HTML only ever contains the relevant fields.
   * persistCurrentStep() (case 2) mirrors this same field list so the
   * two can't drift out of sync.
   */
  private function buildStep2(array &$form, FormStateInterface $form_state): void {
    $stored = $this->session->getStepData('step3');
    $requestType = $this->session->getStepData('step1')['request_type'] ?? 'kaufen';
    $propertyType = $this->session->getStepData('step2')['property_type'] ?? 'apartment';

    // Exposed for QA/debugging and as a CSS hook if ever needed — not
    // used to hide/show anything here, the conditional logic above
    // already only builds the relevant elements.
    $form['step_content']['#attributes']['data-request-type'] = $requestType;
    $form['step_content']['#attributes']['data-property-type'] = $propertyType;

    foreach ($this->step2FieldsForSelection($requestType, $propertyType) as $field) {
      $form['step_content'][$field] = $this->buildStep2Field($field, $stored, $requestType, $propertyType);
    }
  }

  /**
   * Returns the Step 2 field keys relevant to a Gesuchsart+Objektart
   * combination, in display order.
   *
   * Shared by buildStep2() (which fields to render) and
   * persistCurrentStep() (which fields to read out of the submission
   * and save) so the two can never disagree about what's on screen.
   *
   * @return string[]
   */
  private function step2FieldsForSelection(string $requestType, string $propertyType): array {
    $fields = self::STEP2_FIELDS_BY_PROPERTY_TYPE[$propertyType]
      ?? self::STEP2_FIELDS_BY_PROPERTY_TYPE['apartment'];

    // The one case where a field disappears outright rather than just
    // being relabeled by priceFieldLabel(): renting land has no price
    // field at all, per spec.
    if ($requestType === 'mieten' && $propertyType === 'land') {
      $fields = array_values(array_diff($fields, ['price']));
    }

    return $fields;
  }

  /**
   * Builds a single Step 2 form element by field key.
   *
   * @param string $field
   *   One of the keys returned by step2FieldsForSelection(): area,
   *   rooms, price, baujahr, land_size, usage, parking_type,
   *   vehicle_type, or commercial_type.
   * @param array $stored
   *   The 'step3' TempStore bucket's current values (for defaults).
   * @param string $requestType
   *   'kaufen' or 'mieten' — only used here for the 'price' field's
   *   label (see priceFieldLabel()).
   * @param string $propertyType
   *   The current Objektart — likewise only used for 'price'.
   *
   * @return array
   *   A render array for the field.
   */
  private function buildStep2Field(string $field, array $stored, string $requestType, string $propertyType): array {
    return match ($field) {
      'area' => $this->buildMinMaxPair(
        $this->t('Wohnfläche (m²)'), $stored['area'] ?? [], $this->t('m²'),
      ),
      'rooms' => $this->buildMinMaxPair(
        $this->t('Anzahl der Zimmer'), $stored['rooms'] ?? [], $this->t('Zimmer'),
      ),
      'price' => $this->buildMinMaxPair(
        $this->priceFieldLabel($requestType, $propertyType), $stored['price'] ?? [], $this->t('€'),
      ),
      'land_size' => $this->buildMinMaxPair(
        $this->t('Grundstück (m²)'), $stored['land_size'] ?? [], $this->t('m²'),
      ),
      'baujahr' => $this->buildStep2Select(
        $this->t('Baujahr'), self::BAUJAHR_OPTIONS, $stored['baujahr'] ?? '', $this->t('Egal'),
      ),
      'usage' => $this->buildStep2Select(
        $this->t('Nutzung'), self::USAGE_OPTIONS, $stored['usage'] ?? '', NULL,
      ),
      'parking_type' => $this->buildStep2Select(
        $this->t('Art des Stellplatzes'), self::PARKING_TYPE_OPTIONS, $stored['parking_type'] ?? '', $this->t('Egal'),
      ),
      'vehicle_type' => $this->buildStep2Select(
        $this->t('Fahrzeugtyp'), self::VEHICLE_TYPE_OPTIONS, $stored['vehicle_type'] ?? '', $this->t('Egal'),
      ),
      'commercial_type' => $this->buildStep2Select(
        $this->t('Art der Gewerbeimmobilie'), self::COMMERCIAL_TYPE_OPTIONS, $stored['commercial_type'] ?? '', NULL,
      ),
    };
  }

  /**
   * Step 2's price field label.
   *
   * Depends on BOTH request type and property type, not just request
   * type: Mieten uses "Kaltmiete" for Wohnung/Haus, "Mietpreis" for
   * Garage, and "Miete" for Gewerbe; Kaufen always uses "Kaufpreis".
   * (Mieten + Grundstück has no price field at all — filtered out of
   * the field list in step2FieldsForSelection() before this is ever
   * called for that combination.)
   */
  private function priceFieldLabel(string $requestType, string $propertyType): TranslatableMarkup {
    if ($requestType !== 'mieten') {
      return $this->t('Kaufpreis (€)');
    }

    return match ($propertyType) {
      'garage' => $this->t('Mietpreis (€)'),
      'commercial' => $this->t('Miete (€)'),
      default => $this->t('Kaltmiete (€)'),
    };
  }

  /**
   * Builds a Step 2 dropdown with the wizard's shared select styling.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The field label.
   * @param array<string, string> $options
   *   The dropdown's real options.
   * @param mixed $defaultValue
   *   The stored value, if any.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $emptyOption
   *   Leading placeholder option's label (e.g. "Egal"), or NULL for
   *   fields whose spec doesn't list one (Nutzung, Art der
   *   Gewerbeimmobilie) — those still get Drupal's default blank
   *   "- Select -"-free first option (an actual empty '' value) so
   *   the field isn't pre-populated with a real, meaningful answer.
   */
  private function buildStep2Select(TranslatableMarkup $title, array $options, mixed $defaultValue, ?TranslatableMarkup $emptyOption): array {
    $element = [
      '#type' => 'select',
      '#title' => $title,
      '#options' => $options,
      '#default_value' => $defaultValue,
      '#attributes' => ['class' => ['wizard-input', 'wizard-select']],
    ];

    if ($emptyOption !== NULL) {
      $element['#empty_option'] = $emptyOption;
      $element['#empty_value'] = '';
    }

    return $element;
  }

  /**
   * Step 3's checkbox groups (previously the hardcoded CRITERIA_GROUPS
   * constant, a verbatim transcription of Tab.2.1 of
   * ImmoFirst_260826.xlsx) now live as taxonomy terms in the
   * "search_criteria" vocabulary instead — see
   * immofirst_search_request.install's
   * _immofirst_search_request_criteria_term_definitions() for the
   * same Excel-sourced data (including the two documented exceptions,
   * Grundstück's "Erschlossen" and the "Beim Kauf" sub-category, both
   * handled exactly as before) and SearchCriteriaTermRepository for
   * how buildStep3() below loads and groups them per Objektart.
   */

  /**
   * The three "Beim Kauf" sub-category options from Tab.2.1, verbatim.
   *
   * Per the workbook's note, these only apply when the customer
   * selected Kaufen — see filterCriteriaOptionsForRequestType(). The
   * "Beim Kauf" sub-heading itself is never a taxonomy term (see
   * _immofirst_search_request_criteria_term_definitions()), so unlike
   * these three it needs no runtime filtering at all — it structurally
   * cannot appear.
   *
   * @var string[]
   */
  private const PURCHASE_ONLY_CRITERIA = [
    'Finanzierung gesichert',
    'Eigenkapital vorhanden',
    'Kauf ohne Finanzierung möglich',
  ];

  /**
   * Step 3: "Zusätzliche Kriterien" chip groups + notes.
   *
   * Groups/options are loaded from the search_criteria taxonomy
   * vocabulary via SearchCriteriaTermRepository (a complete,
   * property-specific transcription of Tab.2.1 — see that service and
   * _immofirst_search_request_criteria_term_definitions()) — nothing
   * here invents or drops an option. Each option renders as a
   * pill-style chip (checked checkbox visually hidden, its <label>
   * styled as the chip — see .wizard-checkbox-grid in wizard.css)
   * instead of a plain checkbox list, per FIX 5. Only ONE property
   * type's groups render at a time — the one the visitor picked on
   * Step 2 (falling back to 'apartment' if, for any reason, that
   * hasn't been set yet) — never a merged/generic list and never more
   * than one property type's groups at once.
   */
  private function buildStep3(array &$form, FormStateInterface $form_state): void {
    $propertyType = $this->session->getStepData('step2')['property_type'] ?? 'apartment';
    $requestType = $this->session->getStepData('step1')['request_type'] ?? 'kaufen';
    $stored = $this->session->getStepData('step4');
    // Term IDs (int), not label strings — see extractStep4Values().
    $storedCriteria = array_flip($stored['criteria'] ?? []);

    $groups = $this->criteriaTermRepository->groupsForPropertyType($propertyType);

    $form['step_content']['#attributes']['data-property-type'] = $propertyType;

    // Per the client's Step 3 accordion requirement: the FIRST criteria
    // group ("Ausstattung der Wohnung") always stays open and is not
    // collapsible; every subsequent group is a collapsible accordion
    // section, collapsed by default. This flag is the only thing that
    // decides which of the two a given group becomes — purely a
    // rendering/markup distinction (see is-always-open/is-collapsible
    // below and wizard.js's accordion behavior), it does not change
    // which groups load, their options, or their order.
    $isFirstGroup = TRUE;

    foreach ($groups as $groupLabel => $termOptions) {
      // $termOptions is already [term id => term label], exactly the
      // shape '#options' needs — no extra machineKey()'d array to
      // build here (unlike the old label-string version), since the
      // taxonomy term id itself is a stable, unique option key.
      $options = $this->filterCriteriaOptionsForRequestType($termOptions, $requestType);

      if (!$options) {
        continue;
      }

      $groupKey = SearchCriteriaData::machineKey($groupLabel);

      $defaults = [];
      foreach (array_keys($options) as $tid) {
        if (isset($storedCriteria[$tid])) {
          $defaults[] = $tid;
        }
      }

      // Card-level modifier class: 'is-always-open' for the first group
      // (no collapse behavior at all — see wizard.js, which never
      // attaches its accordion toggle to this class), 'is-collapsible'
      // for every group after it (collapsed by default via wizard.css;
      // wizard.js adds the click/keyboard toggle that flips
      // 'is-expanded'). This is the only per-group difference; the
      // fieldset, its icon, and its checkbox options are built exactly
      // the same way either way.
      $stateClass = $isFirstGroup ? 'is-always-open' : 'is-collapsible';

      $form['step_content']['criteria_groups'][$groupKey] = [
        '#type' => 'fieldset',
        '#title' => Markup::create(
          $this->criteriaGroupIcon($groupLabel)
          . '<span>' . $groupLabel . '</span>'
          . $this->criteriaGroupChevron()
        ),
        '#attributes' => ['class' => ['wizard-criteria-card', $stateClass]],
        'options' => [
          '#type' => 'checkboxes',
          '#options' => $options,
          '#default_value' => $defaults,
          // 'options' has no #title, so Drupal never wraps it in its
          // own <fieldset>, and core's checkboxes.html.twig preprocess
          // only ever copies the element's #id onto its own wrapper
          // <div> — never #attributes/class. A plain '#attributes'
          // class here would only cascade onto each bare <input>
          // (Checkboxes::processCheckboxes propagates #attributes to
          // every child checkbox), never onto anything that actually
          // wraps the option rows. #prefix/#suffix instead put a real
          // <div class="wizard-checkbox-grid"> around this exact same
          // element — see wizard.css's ".wizard-checkbox-grid > div"
          // rule for why one more level of unwrapping is still needed
          // below that (Drupal's own #id-only wrapper div).
          '#prefix' => '<div class="wizard-checkbox-grid">',
          '#suffix' => '</div>',
        ],
      ];

      $isFirstGroup = FALSE;
    }

    $form['step_content']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Besondere Wünsche oder Hinweise'),
      '#placeholder' => $this->t('Hier können Sie Ihre besonderen Wünsche oder Hinweise eingeben...'),
      '#default_value' => $stored['notes'] ?? '',
      '#rows' => 3,
      '#attributes' => ['class' => ['wizard-textarea']],
    ];
  }

  /**
   * Applies Tab.2.1's "Beim Kauf" condition to one criteria group's
   * term options.
   *
   * The three PURCHASE_ONLY_CRITERIA options are only kept for
   * Kaufen; for Mieten they're simply absent from the render array
   * (not CSS-hidden), so they can never be checked, submitted, or
   * persisted for a rental request. A no-op for any group that
   * doesn't contain these labels (every group except "Bonität &
   * zusätzliche Angaben").
   *
   * @param array<int, string> $options
   *   One group's [term id => term label] pairs, as loaded from
   *   SearchCriteriaTermRepository::groupsForPropertyType().
   * @param string $requestType
   *   The session's step1 request_type ('kaufen' or 'mieten').
   *
   * @return array<int, string>
   *   The same [term id => term label] pairs, with
   *   PURCHASE_ONLY_CRITERIA removed unless $requestType is 'kaufen'.
   */
  private function filterCriteriaOptionsForRequestType(array $options, string $requestType): array {
    if ($requestType === 'kaufen') {
      return $options;
    }

    return array_diff($options, self::PURCHASE_ONLY_CRITERIA);
  }

  /**
   * Picks a header icon for a Step 3 criteria group, by keyword.
   *
   * Purely decorative styling — falls back to a generic list icon for
   * any group label that doesn't match a known keyword, so nothing in
   * a group loaded from SearchCriteriaTermRepository ever renders
   * without an icon.
   *
   * @return string
   *   A <span> wrapping the icon SVG.
   */
  private function criteriaGroupIcon(string $groupLabel): string {
    $icon = match (TRUE) {
      str_contains($groupLabel, 'Ausstattung') => 'home',
      str_contains($groupLabel, 'Gebäude') || str_contains($groupLabel, 'Zustand') => 'building',
      str_contains($groupLabel, 'Haustyp') => 'home',
      str_contains($groupLabel, 'Park') => 'car',
      str_contains($groupLabel, 'Art') => 'car',
      str_contains($groupLabel, 'Lage') => 'map-pin',
      str_contains($groupLabel, 'Außenbereich') || str_contains($groupLabel, 'Grundstück & Außenbereich') => 'tree',
      str_contains($groupLabel, 'Grundstücksart') => 'map-pin',
      str_contains($groupLabel, 'Bebauung') || str_contains($groupLabel, 'Erschlossen') => 'building',
      str_contains($groupLabel, 'Größe') => 'ruler',
      str_contains($groupLabel, 'Nutzung') => 'layers',
      str_contains($groupLabel, 'Bonität') => 'shield-check',
      str_contains($groupLabel, 'Sonstige') => 'list',
      default => 'list',
    };

    return '<span class="wizard-criteria-card__icon">' . $this->overviewIcon($icon, 18) . '</span>';
  }

  /**
   * The open/collapsible indicator chevron in a Step 3 card header.
   *
   * Purely decorative/state-indicating — wizard.css rotates it to
   * point up for an always-open or expanded card and down for a
   * collapsed one; wizard.js is what actually toggles 'is-expanded' on
   * click for collapsible cards. This markup itself is identical
   * either way (aria-hidden, no interactive attributes of its own —
   * the click/keyboard handling targets the card's <legend>).
   *
   * The same markup is used for the always-open card and every
   * collapsible one — wizard.css's .is-always-open/.is-collapsible
   * rules are what actually rotate it, based on the card's own class,
   * so this helper needs no arguments.
   *
   * @return string
   *   A <span> wrapping the chevron SVG.
   */
  private function criteriaGroupChevron(): string {
    // Purely visual; the accordion's actual accessible state
    // (role="button"/aria-expanded) is set by wizard.js on the card's
    // <legend>, not here.
    return '<span class="wizard-criteria-card__chevron" aria-hidden="true">'
      . $this->overviewIcon('chevron-down', 18)
      . '</span>';
  }

  /**
   * Step 4: Kontaktdaten (Vorname / Nachname / E-Mail / WhatsApp /
   * Datenschutz+AGB consent). The final "Suchauftrag anlegen" submit
   * and the privacy note below it are built in buildForm(), since
   * they're shared #actions/#step_footer infrastructure rather than
   * step-specific fields.
   */
  private function buildStep4(array &$form, FormStateInterface $form_state): void {
    $stored = $this->session->getStepData('step5');

    $form['step_content']['firstname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vorname'),
      // '#required' => TRUE, // TEMP: button validation disabled for now
      '#placeholder' => $this->t('z. B. Max'),
      '#default_value' => $stored['firstname'] ?? '',
      '#attributes' => ['class' => ['wizard-input']],
    ];

    $form['step_content']['lastname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nachname'),
      // '#required' => TRUE, // TEMP: button validation disabled for now
      '#placeholder' => $this->t('z. B. Mustermann'),
      '#default_value' => $stored['lastname'] ?? '',
      '#attributes' => ['class' => ['wizard-input']],
    ];

    $form['step_content']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('E-Mail-Adresse'),
      // '#required' => TRUE, // TEMP: button validation disabled for now
      '#placeholder' => $this->t('z. B. max@mustermann.de'),
      '#default_value' => $stored['email'] ?? '',
      '#attributes' => ['class' => ['wizard-input']],
    ];

    $form['step_content']['phone_group'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-phone-group']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('WhatsApp-Nummer'),
        '#attributes' => ['class' => ['wizard-phone-group__label', 'form-required']],
      ],
      'fields' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['wizard-phone-group__row']],
        'phone_country' => [
          '#type' => 'select',
          '#title' => $this->t('Ländervorwahl'),
          '#title_display' => 'invisible',
          '#options' => self::PHONE_COUNTRY_OPTIONS,
          '#default_value' => $stored['phone_country'] ?? '+49',
          '#attributes' => ['class' => ['wizard-select', 'wizard-phone-group__country']],
        ],
        'phone' => [
          '#type' => 'tel',
          '#title' => $this->t('WhatsApp-Nummer'),
          '#title_display' => 'invisible',
          // '#required' => TRUE, // TEMP: button validation disabled for now
          '#placeholder' => $this->t('z. B. 171 1234567'),
          '#default_value' => $stored['phone_local'] ?? '',
          '#attributes' => ['class' => ['wizard-input', 'wizard-phone-group__number']],
        ],
      ],
    ];

    $form['step_content']['consent'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create($this->consentLabelMarkup()),
      // '#required' => TRUE, // TEMP: button validation disabled for now
      '#default_value' => $stored['consent'] ?? FALSE,
      '#attributes' => ['class' => ['wizard-checkbox']],
    ];
  }

  /**
   * Builds the Step 4 consent checkbox label with inline policy links.
   *
   * Links point at the same /datenschutz and /agb paths used in the
   * page's own trust footer / the theme's global footer "Rechtliches"
   * column — no new routes are declared here.
   */
  private function consentLabelMarkup(): string {
    $prefix = $this->t('Ich akzeptiere die');
    $privacy = $this->t('Datenschutzerklärung');
    $and = $this->t('und die');
    $agb = $this->t('AGB');

    return "{$prefix} <a href=\"/datenschutz\" target=\"_blank\" rel=\"noopener\">{$privacy}</a> {$and} <a href=\"/agb\" target=\"_blank\" rel=\"noopener\">{$agb}</a>.";
  }

  /**
   * Builds the "lock icon + privacy text" line shown below the Step 4
   * "Suchauftrag anlegen" button (FIX 6). Lives in $form['step_footer'],
   * set from buildForm() — see the note there for why.
   */
  private function buildPrivacyNote(): string {
    $text = $this->t('Ihre Daten bleiben vertraulich und werden nicht weitergegeben.');

    return '<p class="wizard__privacy-note">' . $this->overviewIcon('lock', 16) . '<span>' . $text . '</span></p>';
  }

  /**
   * Step 5: the "Suchauftrag erstellt!" success screen (FIX 7).
   *
   * Not a form — no fields, no Weiter/Zurück/finish actions (see
   * buildForm(), which skips building #actions entirely for this
   * step). $form['#immofirst_step_titles'][5] still equals
   * "Suchauftrag erstellt!", but the standard title/subtitle header
   * is suppressed for this step in the twig template in favor of the
   * icon+title+description built here, matching the design.
   *
   * The reference number is derived from the just-created node the
   * same way ThankYouController does (untouched file) — same
   * "SA-<year>-<6-digit id>" format — but computed here directly
   * since this screen no longer receives it via a redirect querystring.
   */
  private function buildStep5(array &$form, FormStateInterface $form_state): void {
    $nodeId = $form_state->get('created_node_id');
    $referenceNumber = is_numeric($nodeId) ? sprintf('SA-%s-%06d', date('Y'), (int) $nodeId) : NULL;

    $form['step_content']['success'] = [
      '#markup' => Markup::create($this->buildSuccessScreenMarkup($referenceNumber)),
    ];
  }

  /**
   * Builds the Step 5 success screen's markup.
   *
   * @param string|null $referenceNumber
   *   The generated "SA-2026-000123"-style reference number, or NULL
   *   if no node id was available (defensive fallback — the reference
   *   card is simply omitted in that case).
   *
   * @return string
   *   Raw HTML for the success screen.
   */
  private function buildSuccessScreenMarkup(?string $referenceNumber): string {
    $title = $this->t('Suchauftrag erstellt!');
    $text = $this->t('Vielen Dank! Ihr Suchauftrag wurde erfolgreich gespeichert.');

    $item1 = $this->t('Sie erhalten passende Angebote per E-Mail oder WhatsApp.');
    $item2 = $this->t('Sie können Ihren Suchauftrag jederzeit bearbeiten oder löschen.');
    $item3 = $this->t('Ihre Daten bleiben vertraulich und werden nicht weitergegeben.');

    $reference = '';
    if ($referenceNumber !== NULL) {
      $referenceLabel = $this->t('Ihre Suchauftragsnummer');
      $copyLabel = $this->t('Suchauftragsnummer kopieren');

      $reference = <<<HTML
<div class="wizard-success__reference">
  <div class="wizard-success__reference-text">
    <span class="wizard-success__reference-label">{$referenceLabel}</span>
    <span class="wizard-success__reference-number" data-wizard-copy-value="{$referenceNumber}">{$referenceNumber}</span>
  </div>
  <button type="button" class="wizard-success__copy-btn" data-wizard-copy-button aria-label="{$copyLabel}">
    {$this->overviewIcon('copy', 18)}
  </button>
</div>
HTML;
    }

    return <<<HTML
<div class="wizard-success">
  <span class="wizard-success__icon" aria-hidden="true">{$this->overviewIcon('check', 32)}</span>
  <h1 class="wizard-success__title">{$title}</h1>
  <p class="wizard-success__text">{$text}</p>
  <ul class="wizard-success__list">
    <li>
      <span class="wizard-success__list-icon" aria-hidden="true">{$this->overviewIcon('envelope', 20)}</span>
      <span>{$item1}</span>
    </li>
    <li>
      <span class="wizard-success__list-icon" aria-hidden="true">{$this->overviewIcon('pencil', 20)}</span>
      <span>{$item2}</span>
    </li>
    <li>
      <span class="wizard-success__list-icon" aria-hidden="true">{$this->overviewIcon('lock', 20)}</span>
      <span>{$item3}</span>
    </li>
  </ul>
  {$reference}
</div>
HTML;
  }

  /**
   * Builds a "Minimum / bis / Maximum" numeric field pair.
   */
  private function buildMinMaxPair(mixed $title, array $stored, mixed $unitSuffix): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['wizard-minmax']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $title,
        '#attributes' => ['class' => ['wizard-minmax__label']],
      ],
      'fields' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['wizard-minmax__row']],
        'min' => [
          '#type' => 'number',
          '#title' => $this->t('Minimum'),
          '#placeholder' => $this->t('Egal'),
          '#default_value' => $stored['min'] ?? NULL,
          '#min' => 0,
          '#step' => 1,
          '#attributes' => ['class' => ['wizard-input', 'wizard-input--number']],
        ],
        'separator' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '–',
          '#attributes' => ['class' => ['wizard-minmax__separator']],
        ],
        'max' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum'),
          '#placeholder' => $this->t('Egal'),
          '#default_value' => $stored['max'] ?? NULL,
          '#min' => 0,
          '#step' => 1,
          '#attributes' => ['class' => ['wizard-input', 'wizard-input--number']],
        ],
        'unit' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $unitSuffix,
          '#attributes' => ['class' => ['wizard-minmax__unit']],
        ],
      ],
    ];
  }

  /* ==========================================================
   * VALIDATION
   * ========================================================== */

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $triggering = $form_state->getTriggeringElement();
    $name = $triggering['#name'] ?? '';

    // "Zurück" already skips validation entirely via #limit_validation_errors.
    if ($name === 'wizard_back') {
      return;
    }

    $step = $form_state->get('step');

    // TEMP: button validation disabled for now — re-enable by
    // uncommenting this block (and the '#required' => TRUE lines
    // throughout the step builders) once QA no longer needs to click
    // through the wizard with empty/partial fields.
    //
    // if ($step === 2) {
    //   $requestType = $this->session->getStepData('step1')['request_type'] ?? 'kaufen';
    //   $propertyType = $this->session->getStepData('step2')['property_type'] ?? 'apartment';
    //   $minMaxLabels = [
    //     'area' => $this->t('Wohnfläche'),
    //     'rooms' => $this->t('Anzahl der Zimmer'),
    //     'price' => $this->t('Preis'),
    //     'land_size' => $this->t('Grundstück'),
    //   ];
    //   foreach ($this->step2FieldsForSelection($requestType, $propertyType) as $field) {
    //     if (isset($minMaxLabels[$field])) {
    //       $this->validateMinMax($form_state, $field, $minMaxLabels[$field]);
    //     }
    //   }
    // }
  }

  private function validateMinMax(FormStateInterface $form_state, string $key, mixed $label): void {
    $min = $form_state->getValue(['step_content', $key, 'fields', 'min']);
    $max = $form_state->getValue(['step_content', $key, 'fields', 'max']);

    if ($min !== '' && $min !== NULL && $max !== '' && $max !== NULL && (float) $min > (float) $max) {
      $form_state->setErrorByName(
        'step_content][' . $key . '][fields][min',
        $this->t('@label: Minimum darf nicht größer als Maximum sein.', ['@label' => $label]),
      );
    }
  }

  /* ==========================================================
   * SUBMIT HANDLERS
   * ========================================================== */

  public function submitNext(array &$form, FormStateInterface $form_state): void {
    $this->persistCurrentStep($form_state);

    $step = min($form_state->get('step') + 1, self::TOTAL_STEPS);
    $form_state->set('step', $step);
    $this->session->setCurrentStep($step);
    $form_state->setRebuild(TRUE);
  }

  public function submitBack(array &$form, FormStateInterface $form_state): void {
    $step = max($form_state->get('step') - 1, 1);
    $form_state->set('step', $step);
    $this->session->setCurrentStep($step);
    $form_state->setRebuild(TRUE);
  }

  public function submitFinish(array &$form, FormStateInterface $form_state): void {
    $this->persistCurrentStep($form_state);

    $data = $this->session->getData();
    $node = $this->nodeCreator->createFromWizardData($data);

    $form_state->set('created_node_id', $node->id());

    // session->clear() resets TempStore's own current-step/data back to
    // defaults, so a future fresh visit to /suchauftrag-erstellen
    // correctly starts over at Step 1 rather than resuming on a
    // "success" step that no longer has any data behind it. The
    // in-flight $form_state, by contrast, is this one request's
    // rebuild-and-render cycle — setting its step to 5 here is what
    // makes THIS response show the success screen (Step 5) once,
    // without persisting "5" as a resumable step in TempStore.
    $this->session->clear();
    $form_state->set('step', 5);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Required by FormBase, but all real logic lives in the per-button
   * #submit callbacks above (::submitNext, ::submitBack, ::submitFinish)
   * since each button needs different behavior.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Saves the current step's submitted values into PrivateTempStore.
   *
   * NOTE ON BUCKETING (no service/node-creation changes — see the
   * class docblock's step-numbering note): visual Step 1 now collects
   * request_type, property_type, AND location/radius all at once, so
   * it writes into three separate backend buckets (step1, step2, and
   * step3's location/radius) in a single call. Visual Step 2 then
   * read-merges the rest of 'step3' (rooms/area/price/baujahr) on top
   * of the location/radius Step 1 already wrote. Visual Step 3 writes
   * 'step4' (criteria/notes) and visual Step 4 writes 'step5'
   * (contact) — both unchanged from before, just called from their
   * new visual step numbers.
   */
  private function persistCurrentStep(FormStateInterface $form_state): void {
    $step = $form_state->get('step');
    $values = $form_state->getValue('step_content') ?? [];

    switch ($step) {
      case 1:
        $this->session->setStepData('step1', [
          'request_type' => $values['request_type'] ?? NULL,
        ]);

        $this->session->setStepData('step2', [
          'property_type' => $values['property_type'] ?? NULL,
        ]);

        $locationFields = $values['location_section']['fields'] ?? [];
        $step3 = $this->session->getStepData('step3');
        $step3['location'] = $locationFields['location'] ?? ($step3['location'] ?? '');
        $step3['radius'] = $locationFields['radius'] ?? ($step3['radius'] ?? '25');
        $this->session->setStepData('step3', $step3);
        break;

      case 2:
        $requestType = $this->session->getStepData('step1')['request_type'] ?? 'kaufen';
        $propertyType = $this->session->getStepData('step2')['property_type'] ?? 'apartment';

        $step3 = $this->session->getStepData('step3');
        foreach ($this->step2FieldsForSelection($requestType, $propertyType) as $field) {
          $step3[$field] = $this->extractStep2FieldValue($field, $values);
        }
        $this->session->setStepData('step3', $step3);
        break;

      case 3:
        $this->session->setStepData('step4', $this->extractStep4Values($values));
        break;

      case 4:
        $phoneFields = $values['phone_group']['fields'] ?? [];
        $countryCode = trim((string) ($phoneFields['phone_country'] ?? ''));
        $localNumber = trim((string) ($phoneFields['phone'] ?? ''));

        $this->session->setStepData('step5', [
          'firstname' => trim((string) ($values['firstname'] ?? '')),
          'lastname' => trim((string) ($values['lastname'] ?? '')),
          'email' => trim((string) ($values['email'] ?? '')),
          // Single combined string, exactly as SearchRequestNodeCreator
          // and the field_search_criteria JSON payload already expect.
          'phone' => trim($countryCode . ' ' . $localNumber),
          'phone_country' => $countryCode,
          'phone_local' => $localNumber,
          'consent' => (bool) ($values['consent'] ?? FALSE),
        ]);
        break;
    }
  }

  /**
   * Reads one Step 2 field's submitted value out of $values, in the
   * shape persistCurrentStep() stores it under 'step3'.
   *
   * @param string $field
   *   One of step2FieldsForSelection()'s field keys.
   * @param array<string, mixed> $values
   *   $form_state->getValue('step_content') from Step 2's submission.
   *
   * @return array{min: mixed, max: mixed}|string
   *   A min/max pair for the numeric fields (area, rooms, price,
   *   land_size), or a plain string for the dropdown fields.
   */
  private function extractStep2FieldValue(string $field, array $values): array|string {
    return match ($field) {
      'area', 'rooms', 'price', 'land_size' => [
        'min' => $values[$field]['fields']['min'] ?? NULL,
        'max' => $values[$field]['fields']['max'] ?? NULL,
      ],
      'baujahr', 'usage', 'parking_type', 'vehicle_type', 'commercial_type' => $values[$field] ?? '',
      default => '',
    };
  }

  /**
   * Flattens all dynamic checkbox groups' checked options into one list.
   *
   * Since buildStep3() now keys each checkbox '#options' entry by its
   * search_criteria taxonomy term id (not a machine-key'd label
   * string), the checked keys collected here already ARE term ids —
   * exactly what SearchRequestNodeCreator needs for field_criteria's
   * entity reference values, with no separate label→term lookup step.
   *
   * @param array<string, mixed> $values
   *
   * @return array{criteria: int[], notes: string}
   */
  private function extractStep4Values(array $values): array {
    $criteria = [];

    foreach ($values['criteria_groups'] ?? [] as $group) {
      $checked = array_filter($group['options'] ?? []);
      foreach (array_keys($checked) as $key) {
        $criteria[] = (int) $key;
      }
    }

    return [
      'criteria' => $criteria,
      'notes' => trim((string) ($values['notes'] ?? '')),
    ];
  }

  /* ==========================================================
   * AJAX CALLBACKS
   * ========================================================== */

  /**
   * Standard AJAX callback: re-renders the form in place (step change).
   *
   * Also used for the Step 4 "Suchauftrag anlegen" button: the success
   * screen (visual Step 5) now renders in place instead of redirecting
   * (see submitFinish()), so there's nothing a dedicated finish
   * callback needs to do beyond what this already does.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

}