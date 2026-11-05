# Brand: RHIS huisstijl

Copy of the RHIS house style from `rhisijmuiden/rhis-huisstijl` (commit 7665a81),
kept here so the panel always builds with the same brand.

- `tokens/` colors, type, spacing and radius (`tokens.css`, `tokens.json`)
- `logo/` logos, app icon and favicon
- `fonts/` Archivo, self-hosted (SIL Open Font License)
- `huisstijlgids.md` how to apply it

The panel uses these in `panel/public/assets/app.css` and `panel/public/brand/`.
To use another brand: replace the files here and in `panel/public/brand/`, and
change the color tokens at the top of `app.css`.
