# Immobilien Theme (Drupal 11)

Premium real-estate landing theme built to match the approved design mockup.

## Requirements
- Drupal core `^11`
- PHP 8.3+
- No contrib dependencies

## Installation
1. Copy the entire `immobilien_theme/` directory into your site at:
   ```
   web/themes/custom/immobilien_theme
   ```
2. Log in as an administrator and visit **Appearance** (`/admin/appearance`).
3. Under **Uninstalled themes**, find **Immobilien Theme** and click **Install and set as default**.
4. Clear caches:
   ```bash
   drush cr
   ```

## What's included
- **Custom homepage** (`page--front.html.twig`) matching the mockup:
  - Hero with gradient background, animated blur orbs, CSS wave, and hero image
  - Trust bar with 4 checkmarks
  - "Warum sind gute Immobilien..." section with 2 feature cards + search-form card
  - "So einfach geht's" 3-step section with icon cards
  - Final CTA
- **Design tokens** in `css/tokens.css` (colors, gradients, radii, shadows, type scale, spacing)
- **Wave & orb effects** in `css/waves.css` (pure CSS/SVG — no external assets)
- **Fully responsive** breakpoints at 1024px (tablet) and 640px (mobile) — see `css/responsive.css`
- **Inline SVG icons** for all icons in the design
- **Custom SVG logo** in `logo/logo.svg`
- **Accessible**: skip-link, ARIA landmarks, `prefers-reduced-motion` support, keyboard focus rings
- **Drupal 11 compatible**: `core_version_requirement: ^11`, Twig 3, `core/once` usage, no deprecated APIs

## File map
```
immobilien_theme/
├── immobilien_theme.info.yml         # Drupal 11 theme definition
├── immobilien_theme.libraries.yml    # Asset libraries
├── immobilien_theme.theme            # Preprocess hooks
├── logo/logo.svg                     # Brand mark
├── screenshot.png                    # Appears in Appearance UI
├── css/
│   ├── reset.css                     # Modern reset
│   ├── tokens.css                    # Design tokens
│   ├── global.css                    # Base styles + buttons
│   ├── homepage.css                  # Homepage sections
│   ├── waves.css                     # Wave & orb effects
│   └── responsive.css                # Media queries
├── js/
│   ├── global.js                     # Reveal-on-scroll
│   └── homepage.js                   # Smooth scroll + form validation
├── images/
│   ├── hero-family-house.png         # Hero photo
│   └── icons/                        # (Icons are inline SVG in templates/icons)
└── templates/
    ├── layout/
    │   ├── html.html.twig
    │   ├── page.html.twig
    │   └── page--front.html.twig     # Homepage
    └── icons/*.svg.twig              # Reusable inline SVG icon partials
```

## Customization

### Colors & gradients
Edit CSS custom properties in `css/tokens.css` — every color, gradient, radius and shadow is a variable.

### Copy
All homepage copy lives in `templates/layout/page--front.html.twig` and is wrapped in `{{ '...'|t }}` for translation via `admin/config/regional/translate`.

### Hero image
Replace `images/hero-family-house.png` with your own photo (recommended 1600×1200 or larger, JPG/PNG).

## Browser support
Modern evergreen browsers. Uses CSS Grid, `clamp()`, custom properties, and `backdrop-filter`.
