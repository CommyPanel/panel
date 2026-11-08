# Languages of the panel

One file per language: `lang/<code>.json` maps each English sentence to its translation. Laravel's own messages (validation errors, sign-in, passwords, pagination) are in `lang/<code>/*.php`, taken from the MIT-licensed [laravel-lang/lang](https://github.com/Laravel-Lang/lang) project. English is the default and needs no file; a sentence missing from a file shows in English. See _Translating the panel_ in `CONTRIBUTING.md` for how to improve a language or add a new one.

| Code | Language   | Status                                                    |
| ---- | ---------- | --------------------------------------------------------- |
| `en` | English    | Source language                                           |
| `fr` | Français   | Drafted with a tool, **needs review by a native speaker** |
| `de` | Deutsch    | Drafted with a tool, **needs review by a native speaker** |
| `es` | Español    | Drafted with a tool, **needs review by a native speaker** |
| `ru` | Русский    | Drafted with a tool, **needs review by a native speaker** |
| `uk` | Українська | Drafted with a tool, **needs review by a native speaker** |
| `tr` | Türkçe     | Drafted with a tool, **needs review by a native speaker** |

When a native speaker has reviewed a language, change its status here in the same pull request.
