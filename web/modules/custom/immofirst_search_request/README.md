# ImmoFirst Search Request — "Suchauftrag erstellen" Wizard

Drupal 11 module implementing the 5-step AJAX wizard from
`ImmoFirst_260826.xlsx` (Tab.2 "Suchauftrag erstellen" + Tab.2.1
"Addit. Search Criteria").

## Install

```bash
# 1. Copy this folder into your site
cp -r immofirst_search_request web/modules/custom/

# 2. Enable it
drush en immofirst_search_request -y
```

Enabling the module runs `hook_install()`, which adds the
`field_search_criteria` field (Long text, plain) to the **existing**
`suchauftrag` content type. No new content type is created, and no
existing field is touched.

## Routes

| Path | Purpose |
|---|---|
| `/suchauftrag-erstellen` | The 5-step wizard |
| `/suchauftrag-erstellen/danke` | Confirmation page (redirected to after successful submit) |
| `/suchauftrag-erstellen/standort-autocomplete` | JSON endpoint backing the Step 3 "Standort" autocomplete field |

## How the steps map to data

| Step | Title | Saves to (TempStore key) | Final node field(s) |
|---|---|---|---|
| 1 | Gesuchsart | `step1.request_type` | `field_request_type` |
| 2 | Objektart | `step2.property_type` | `field_property_icon` |
| 3 | Suchdetails | `step3.location/rooms/area/price/description` | `field_location`, `field_rooms` (min only), `field_area` (min only), `field_price` (min only), `field_description` |
| 4 | Zusätzliche Kriterien | `step4.criteria[]`, `step4.notes` | → `field_search_criteria` JSON only (not mapped to a dedicated node field) |
| 5 | Kontaktdaten | `step5.firstname/lastname/email/phone/consent` | → `field_search_criteria` JSON only |

Maximum values for rooms/area/price, all Step 4 checkbox selections,
and all Step 5 contact details are preserved in `field_search_criteria`
as JSON — nothing is discarded, per spec: only the *minimums* go into
the individual node fields.

## Property type → criteria groups (Step 4)

The checkbox groups shown in Step 4 depend on the Step 2 selection,
transcribed verbatim from Tab.2.1 into `src/Service/SearchCriteriaData.php`:

- **Wohnung** (`apartment`): Ausstattung, Gebäude & Zustand, Parkmöglichkeiten, Lage im Gebäude, Außenbereiche, Sonstige Kriterien, Bonität & zusätzliche Angaben
- **Haus** (`house`): Ausstattung, Haustyp, Grundstück & Außenbereich, Parkmöglichkeiten, Zustand, Nutzung, Bonität & zusätzliche Angaben
- **Grundstück** (`land`): Grundstücksart, Bebauung, Erschlossen, Bebauungsmöglichkeiten, Lage / Besonderheiten, Bonität & zusätzliche Angaben
- **Garage** (`garage`): Art, Nutzung, Größe / Nutzung, Bonität & zusätzliche Angaben

## A note on reconciling the Excel image vs. the written spec

The workbook's Tab.2 images show Step 1 merging Gesuchsart + Objektart
+ Standort into a single screen (4 input steps + 1 success screen). The
written task spec explicitly lists 5 distinct steps with their own
field mappings (Gesuchsart / Objektart / Suchdetails / Zusätzliche
Kriterien / Kontaktdaten). This module follows the **written spec's
step structure** — since it's unambiguous and necessary for correct
field mapping — while using the **image for visual styling** (card
design, colors, spacing, accordion look). If you'd rather match the
image's exact screen groupings 1:1, Steps 1+2 can be merged into a
single screen; say the word and I'll restructure it.

One mockup variant (image10) also shows a 5th property type
("Gewerbe") not present in the written spec's mapping table or in the
existing `field_property_icon` field's allowed values — this was
treated as a superseded draft and left out. Add it to
`SearchCriteriaData::groups()` and the `PROPERTY_TYPES` constant in
`SearchRequestWizardForm.php` if you do want it, plus a corresponding
allowed-value on `field_property_icon`.

## Known follow-ups (flagged, not silently skipped)

- **Standort autocomplete** uses a small static list of ~50 major
  German cities (`LocationAutocompleteController`), not a geocoding
  service — sufficient for real autocomplete behavior without adding
  an external dependency the spec didn't request.
- **Character counter / number input JS** are the only client-side
  enhancements; no validation logic is duplicated in JS per spec.
