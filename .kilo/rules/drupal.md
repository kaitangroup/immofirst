# ImmoFirst Drupal 11 Rules

## Project
- Drupal 11
- Theme: suchauftrag_theme
- Module: immofirst_search_request
- Webform + Views + AJAX

## Constraints
- Never rename Webform IDs or machine names.
- Preserve Drupal AJAX behaviors.
- Preserve cache metadata.
- Do not duplicate form fields in Twig.

## Default behavior
- Do not scan the entire project.
- Only read files explicitly referenced with @.
- Assume the existing architecture unless I request refactoring.
- Return complete updated files only when requested.