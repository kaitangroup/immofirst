# Migrating to Twig Tweak 4.x

Twig Tweak 4.x is largely template-compatible with 3.x — most existing Twig
templates will continue to work without changes. The bulk of breaking changes
affect PHP code that **extends, instantiates, or directly calls** Twig Tweak
classes, plus a few Drush command renames.

This document lists every notable change. BC breaks are flagged with **[BC]**.

---

## Dependencies

**[BC]** Minimum platform requirements have been raised:

| Requirement | 3.x                  | 4.x       |
|-------------|----------------------|-----------|
| PHP         | `>=8.1`              | `>=8.4`   |
| Drupal core | `^10.3 \|\| ^11.0`   | `^11.2`   |
| Twig        | `^3.10.3`            | `^3.21`   |
| Drush       | `^10 \|\| ^11` (legacy services) | `^12+` (Symfony Console autodiscovery) |

Drupal 10 is no longer supported. Sites still on Drupal 10 must stay on the
3.x branch.

The legacy Drush 10/11 service registration block was removed from
`composer.json`:

```diff
- "extra": {
-     "drush": {
-         "services": {
-             "drush.services.yml": "^10 || ^11"
-         }
-     }
- }
```

---

## Drush commands

### `twig-tweak:debug` was split into four commands **[BC]**

The single `twig-tweak:debug` command (alias `twig-debug`) is gone. It has been
replaced with four focused commands, each registered via a
`#[AsCommand]` attribute:

| 3.x                         | 4.x                              |
|-----------------------------|----------------------------------|
| `twig-tweak:debug --filter=…` (functions section) | `twig-tweak:debug:functions` |
| `twig-tweak:debug --filter=…` (filters section)   | `twig-tweak:debug:filters`   |
| `twig-tweak:debug --filter=…` (tests section)     | `twig-tweak:debug:tests`     |
| _(not previously available)_                      | `twig-tweak:debug:loaders`   |

The `--filter` option no longer exists. Pipe the command output through
`grep` if filtering is required.

Old aliases (`twig-debug`) are not preserved.

### `twig-tweak:validate` removed **[BC]**

The `twig-tweak:validate` command (alias `twig-validate`) and its
`LintCommand` base class have been removed. The 3.x source already carried a
`@todo Remove this in 4.x` marker.

For Twig syntax validation use one of:

* Drupal core's own `drush twig:lint` (Drupal 11) / Symfony's `lint:twig`.
* The `friendsoftwig/twigcs` package, recommended in the 3.x help text.

### New `SignatureFormatter` helper service

The `twig-tweak:debug:functions` / `:filters` / `:tests` commands share a new
`Drupal\twig_tweak\Command\SignatureFormatter` service registered as
`twig_tweak.debug.signature_formatter`. It is a small utility for rendering a
`TwigFunction` / `TwigFilter` / `TwigTest` signature as a human-readable line.

---

## New features

### `drupal_logger()` Twig function

A new function lets templates write to a Drupal logger channel:

```twig
{{ drupal_logger('my_module', 'notice', 'Missing expected field XYZ') }}
```

Signature: `drupal_logger(string $channel, string $level, string $message, array $context = [])`.

Use sparingly — logging from a template is usually a smell, but it can be
useful for diagnosing display issues in production.

---

## API and class structure

### `TwigTweakExtension` is now `final` **[BC]**

`Drupal\twig_tweak\TwigTweakExtension` is declared `final`. Any module
subclassing it must be reworked, e.g. by registering its own `\Twig\Extension\AbstractExtension`
or by listening to the `hook_twig_tweak_functions_alter()` /
`_filters_alter()` / `_tests_alter()` hooks.

### Helper static methods on `TwigTweakExtension` are now `private` **[BC]**

In 3.x almost every `drupal*` / `*Filter` callback was `public static`, which
made it tempting to call them directly from PHP code:

```php
// 3.x — worked, but never an officially supported API.
$build = TwigTweakExtension::drupalEntity('node', '42');
```

In 4.x **all of these are `private static`** and cannot be called from
outside the class. The complete list of methods that flipped visibility:

`drupalBlock`, `drupalRegion`, `drupalEntity`, `drupalEntityForm`,
`drupalField`, `drupalMenu`, `drupalForm`, `drupalImage`, `drupalToken`,
`drupalConfig`, `drupalDump`, `drupalTitle`, `drupalUrl`, `drupalLink`,
`drupalBreadcrumb`, `drupalContextualLinks`, `drupalBreakpoint`,
`tokenReplaceFilter`, `pregReplaceFilter`, `imageStyleFilter`,
`transliterateFilter`, `viewFilter`, `dataUriFilter`, `withFilter`,
`childrenFilter`, `fileUriFilter`, `fileUrlFilter`, `entityUrl`,
`entityLink`, `entityTranslation`, `cacheMetadata`, `phpFilter`.

The only `public static` method retained is the new `drupalLogger()`.

**Migration:** call the underlying services instead, which is what the Twig
extension does internally:

```php
// 4.x replacement for TwigTweakExtension::drupalEntity('node', '42').
$build = \Drupal::service('twig_tweak.entity_view_builder')
  ->build($node);
```

The available services are:

* `twig_tweak.block_view_builder`
* `twig_tweak.region_view_builder`
* `twig_tweak.entity_view_builder`
* `twig_tweak.entity_form_view_builder`
* `twig_tweak.field_view_builder`
* `twig_tweak.image_view_builder`
* `twig_tweak.menu_view_builder`
* `twig_tweak.uri_extractor`
* `twig_tweak.url_extractor`
* `twig_tweak.cache_metadata_extractor`

### View builders and extractors are `final readonly` **[BC]**

The following classes are now `final readonly` with promoted private
constructor properties:

* `View\BlockViewBuilder`
* `View\EntityViewBuilder`
* `View\EntityFormViewBuilder`
* `View\FieldViewBuilder`
* `View\ImageViewBuilder`
* `View\MenuViewBuilder`
* `View\RegionViewBuilder` (properties are `public readonly` on this one)
* `UriExtractor`
* `UrlExtractor`
* `CacheMetadataExtractor`

Consequences:

* They can no longer be subclassed.
* Their previously `protected` properties are now inaccessible. Code that
  relied on reflecting/overriding them must be redesigned (decoration,
  service replacement, or a parallel implementation).

### `EntityFormViewBuilder` constructor signature **[BC]**

A second argument was added:

```diff
- public function __construct(EntityFormBuilderInterface $entity_form_builder)
+ public function __construct(
+     EntityFormBuilderInterface $entity_form_builder,
+     EntityRepositoryInterface $entity_repository,
+ )
```

Anyone instantiating `EntityFormViewBuilder` manually must update the call.
The container service definition (`twig_tweak.entity_form_view_builder`) was
updated to pass `@entity.repository` automatically.

### `EntityFormViewBuilder::build()` adds `$langcode` parameter **[BC]**

```diff
- public function build(EntityInterface $entity, string $form_mode = 'default', bool $check_access = TRUE): array
+ public function build(EntityInterface $entity, string $form_mode = 'default', ?string $langcode = NULL, bool $check_access = TRUE): array
```

Positional callers that passed `$check_access` as the third argument are
silently broken — `false` would now be coerced into the `$langcode`
position, which expects a string or `null`.

**Migration:** use named arguments, or insert an explicit `null` for
`$langcode`:

```php
// 3.x
$builder->build($entity, 'edit', FALSE);

// 4.x
$builder->build($entity, 'edit', NULL, FALSE);
// or:
$builder->build($entity, 'edit', check_access: FALSE);
```

### `drupal_entity_form()` Twig function gains `langcode` **[BC]**

The Twig function mirrors the change above:

```diff
- drupal_entity_form(entity_type, id, form_mode, values, check_access)
+ drupal_entity_form(entity_type, id, form_mode, values, langcode, check_access)
```

Twig templates calling it with positional `check_access` need the same fix:

```twig
{# 3.x #}
{{ drupal_entity_form('node', '42', 'default', {}, false) }}

{# 4.x #}
{{ drupal_entity_form('node', '42', 'default', {}, null, false) }}
{# or use named arguments: #}
{{ drupal_entity_form('node', '42', check_access=false) }}
```

### `FieldViewBuilder::build()` now throws on missing field **[BC]**

In 3.x, requesting a field that did not exist on the entity returned an
empty render array. In 4.x it throws an `\InvalidArgumentException`:

```text
Field "field_xyz" does not exist in "node" entity type.
```

Templates that probed for optional fields by relying on the silent empty
result need to guard the call (e.g. with an `is defined`/`field_exists`
check in a preprocess hook) or wrap it in `try/catch` on the PHP side.

The `$view_mode` parameter was also tightened from untyped to `string|array`.

### `drupal_field()` Twig function `view_mode` is now typed **[BC, minor]**

The Twig function previously declared `$view_mode` without a type; it is now
typed `string`. Passing anything other than a string (e.g. an integer) will
trigger a `TypeError`. Display options arrays go through the underlying
`twig_tweak.field_view_builder` service, which still accepts `string|array`.

### `viewFilter` simplification **[BC, edge case]**

For `FieldItemListInterface` / `FieldItemInterface` inputs, the cache
dependency is now added unconditionally:

```diff
- if ($parent = $object->getParent()) {
-     CacheableMetadata::createFromRenderArray($build)
-         ->addCacheableDependency($parent->getEntity())
-         ->applyTo($build);
- }
+ CacheableMetadata::createFromRenderArray($build)
+     ->addCacheableDependency($object->getEntity())
+     ->applyTo($build);
```

Any code that fed a detached field item (no parent entity) into the `|view`
filter will now error. In practice, field items obtained from a loaded
entity always have a parent, so this should not affect typical templates.

### Closure callbacks instead of `[class, method]` arrays

Inside `TwigTweakExtension::getFunctions()` / `getFilters()`, callables were
rewritten from string/array form to first-class callable syntax
(`self::method(...)` / `\function(...)`). This is internal and does not
affect callers, but anyone alter-hooking those arrays should be aware of
the modern callback shape.

---

## Internal cleanups (not BC, but worth knowing)

* `declare(strict_types=1);` is now in every PHP file, including the test
  module. Hook implementations in custom alter modules should add the same
  declaration to stay consistent.
* All view builder / extractor classes use constructor property promotion.
* `BlockViewBuilder` no longer carries the
  `@see https://www.drupal.org/project/drupal/issues/3212354` workaround for
  blocks returning `null` instead of an array — the workaround was removed
  as obsolete.
* `UrlExtractor::getUrlFromEntity()` no longer short-circuits on an empty
  source value for media entities; the value is forwarded to the file
  storage as-is. Behavior is equivalent for valid input.
* PHPStan is now configured (`phpstan.neon`, level 6).
* PHPCS rules updated: the `NullableTypeForNullDefaultValue` exclusion
  (added in 3.x explicitly to avoid BC breaks) is gone — all nullable
  parameters now use explicit `?type` syntax.
* Test module (`twig_tweak_test`) requires Drupal `>=11.2` and uses
  strict types and `: void` return types on its alter hooks.

---

## Summary of BC breaks

| Area | Change |
|------|--------|
| Platform | PHP 8.4, Drupal 11.2, Twig 3.21, Drush 12+ minimum |
| Drush | `twig-tweak:debug` split into 4 commands; `--filter` removed |
| Drush | `twig-tweak:validate` removed (use `twigcs` or core's lint command) |
| Drush | Aliases `twig-debug`, `twig-validate` removed |
| Twig | `drupal_entity_form()` argument order changed (added `langcode`) |
| Twig | `drupal_field()` `view_mode` strictly typed as string |
| Twig | `|view` filter no longer guards against orphan field items |
| PHP API | `TwigTweakExtension` is `final`; helper methods are `private` |
| PHP API | All view builders and extractors are `final readonly` |
| PHP API | `EntityFormViewBuilder` constructor takes a new `EntityRepositoryInterface` arg |
| PHP API | `EntityFormViewBuilder::build()` argument order changed (added `langcode`) |
| PHP API | `FieldViewBuilder::build()` throws `InvalidArgumentException` for missing fields |
