# Brand

- `dekwerk/` the **Dekwerk** house style: the product's logo, colors, font (Lexend) and style guide (`dekwerk/stijlgids.md`). The panel uses this.
- `rhis/` the RHIS house style, copied from `rhisijmuiden/rhis-huisstijl` (commit 7665a81). RHIS is the hosting company that runs Dekwerk; kept for reference.

Where the panel takes its brand from:

- Name, logo and icon paths: `panel/config/panel.php` (`brand`), read through `App\Support\Brand`.
- The hosting company name customers see: Tools & settings > Branding (default `WHP_PROVIDER_NAME`, else RHIS).
- Colors and font: the tokens at the top of `panel/public/assets/app.css`, copied from `dekwerk/tokens/tokens.css`.
- Logo files served to browsers: `panel/public/brand/` (copies of `dekwerk/logo/`).
