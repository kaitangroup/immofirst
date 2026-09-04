<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

/**
 * Additional-criteria checkbox groups, transcribed verbatim from
 * "Tab.2.1 Addit. Search Criteria" in the source workbook.
 *
 * Keys at the top level are the field_property_icon values (apartment,
 * house, land, garage). Do not add, remove, or reword any option here
 * without going back to the workbook — per spec, this data must match
 * the Excel exactly with nothing invented.
 */
final class SearchCriteriaData {

  /**
   * @return array<string, array<string, array<int, string>>>
   *   [property_type => [group_label => [option_label, ...]]]
   */
  public static function groups(): array {
    return [
      'apartment' => [
        'Ausstattung' => [
          'Balkon', 'Terrasse', 'Garten / Gartennutzung', 'Aufzug', 'Keller',
          'Abstellraum', 'Gäste-WC', 'Einbauküche', 'Barrierefrei / barrierearm',
          'Seniorengerecht', 'Kamin', 'Fußbodenheizung', 'Klimaanlage', 'Smart-Home-System',
        ],
        'Gebäude & Zustand' => [
          'Neubau', 'Erstbezug', 'Erstbezug nach Sanierung', 'Renoviert / saniert', 'Denkmalgeschützt',
        ],
        'Parkmöglichkeiten' => [
          'Garage', 'Doppelgarage', 'Carport', 'Außenstellplätze',
          'Mit Stromanschluss', 'Mit Wallbox / E-Ladestation',
        ],
        'Lage im Gebäude' => [
          'Erdgeschoss', 'Etagenwohnung', 'Penthouse / Dachgeschoss', 'Maisonette',
        ],
        'Außenbereiche' => [
          'Gemeinschaftsgarten', 'Dachterrasse',
        ],
        'Sonstige Kriterien' => [
          'Haustiere erlaubt (bei Miete)', 'Möbliert', 'WG-geeignet',
        ],
        'Bonität & zusätzliche Angaben' => [
          'SCHUFA-Auskunft vorhanden', 'Positive Bonität', 'Einkommensnachweise vorhanden',
          'Selbstauskunft vorhanden', 'Flexible Übergabe / Einzug', 'Langfristiges Interesse',
          'Nichtraucher', 'Keine Haustiere', 'Beim Kauf', 'Finanzierung gesichert',
          'Eigenkapital vorhanden', 'Kauf ohne Finanzierung möglich',
        ],
      ],
      'house' => [
        'Ausstattung' => [
          'Gäste-WC', 'Kamin', 'Fußbodenheizung', 'Klimaanlage', 'Smart-Home-System',
          'Sauna', 'Einbauküche', 'Barrierefrei / barrierearm', 'Seniorengerecht',
        ],
        'Haustyp' => [
          'Einfamilienhaus', 'Doppelhaushälfte', 'Reihenhaus', 'Mehrfamilienhaus', 'Bungalow', 'Villa', 'Landhaus',
        ],
        'Grundstück & Außenbereich' => [
          'Garten', 'Terrasse', 'Balkon', 'Wintergarten', 'Pool', 'Gartenhaus',
        ],
        'Parkmöglichkeiten' => [
          'Garage', 'Doppelgarage', 'Carport', 'Außenstellplätze',
          'Mit Stromanschluss', 'Mit Wallbox / E-Ladestation',
        ],
        'Zustand' => [
          'Neubau', 'Erstbezug', 'Renovierungsbedürftig', 'Modernisiert', 'Denkmalgeschützt',
        ],
        'Nutzung' => [
          'Einliegerwohnung vorhanden', 'Gewerbliche Nutzung möglich', 'Mehrgenerationenhaus geeignet',
        ],
        'Bonität & zusätzliche Angaben' => [
          'SCHUFA-Auskunft vorhanden', 'Positive Bonität', 'Einkommensnachweise vorhanden',
          'Selbstauskunft vorhanden', 'Flexible Übergabe / Einzug', 'Langfristiges Interesse',
          'Nichtraucher', 'Keine Haustiere', 'Beim Kauf', 'Finanzierung gesichert',
          'Eigenkapital vorhanden', 'Kauf ohne Finanzierung möglich',
        ],
      ],
      'land' => [
        'Grundstücksart' => [
          'Baugrundstück', 'Bauerwartungsland', 'Freizeitgrundstück', 'Landwirtschaftliche Fläche', 'Gewerbegrundstück',
        ],
        'Bebauung' => [
          'Sofort bebaubar', 'Mit Baugenehmigung', 'Ohne Baubindung',
        ],
        'Erschlossen' => [
          'Erschlossen', 'Teilerschlossen', 'Nicht erschlossen',
        ],
        'Bebauungsmöglichkeiten' => [
          'Einfamilienhaus möglich', 'Doppelhaus möglich', 'Mehrfamilienhaus möglich', 'Gewerbebebauung möglich',
        ],
        'Lage / Besonderheiten' => [
          'Hanglage', 'Seeblick', 'Feldrandlage', 'Waldnähe', 'Ruhige Lage',
        ],
        'Bonität & zusätzliche Angaben' => [
          'SCHUFA-Auskunft vorhanden', 'Positive Bonität', 'Einkommensnachweise vorhanden',
          'Selbstauskunft vorhanden', 'Flexible Übergabe / Einzug', 'Langfristiges Interesse',
          'Nichtraucher', 'Keine Haustiere', 'Beim Kauf', 'Finanzierung gesichert',
          'Eigenkapital vorhanden', 'Kauf ohne Finanzierung möglich',
        ],
      ],
      'garage' => [
        'Art' => [
          'Garage', 'Doppelgarage', 'Tiefgarage', 'Carport', 'Außenstellplatz', 'Duplex-Stellplatz',
        ],
        'Nutzung' => [
          'Abschließbar', 'Überdacht', 'Mit Stromanschluss', 'Mit Wallbox / E-Ladestation',
        ],
        'Größe / Nutzung' => [
          'Für SUV geeignet', 'Für Motorrad geeignet', 'Extra Stauraum vorhanden',
        ],
        'Bonität & zusätzliche Angaben' => [
          'SCHUFA-Auskunft vorhanden', 'Positive Bonität', 'Einkommensnachweise vorhanden',
          'Selbstauskunft vorhanden', 'Flexible Übergabe / Einzug', 'Langfristiges Interesse',
          'Nichtraucher', 'Keine Haustiere', 'Beim Kauf', 'Finanzierung gesichert',
          'Eigenkapital vorhanden', 'Kauf ohne Finanzierung möglich',
        ],
      ],
    ];
  }

  /**
   * Converts a human label to a stable machine key (for checkbox names).
   *
   * E.g. "Mit Wallbox / E-Ladestation" -> "mit_wallbox_e_ladestation".
   */
  public static function machineKey(string $label): string {
    $key = mb_strtolower($label);
    $key = str_replace(
      ['ä', 'ö', 'ü', 'ß'],
      ['ae', 'oe', 'ue', 'ss'],
      $key
    );
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

    return trim($key, '_');
  }

}
