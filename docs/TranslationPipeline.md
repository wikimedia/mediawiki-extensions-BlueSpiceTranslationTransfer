# Translation Pipeline

## Overview

The new translation pipeline (`src/Pipeline/`) replaces the legacy `EscapeWikitext` + `ignore_tags` approach with a structured **segment → translate → assemble → post-process** architecture. Instead of wrapping wikitext in `<deepl:ignore>` tags and sending the full page to DeepL, this pipeline extracts only translatable text, sends small inline-HTML fragments to DeepL, and reassembles the result — preserving all structural wikitext perfectly.

### Key design principles

1. **DeepL never sees raw wikitext** — only clean HTML fragments (`<b>`, `<i>`, `<a>` tags)
2. **Structure is preserved losslessly** — table markup, headings, lists, templates stay in a "skeleton"
3. **Post-processing is modular** — link translation, template args, magic words are independent steps
4. **Configurable** — each feature (title translation, NS translation, magic words) can be toggled

### Enabling the pipeline

```php
// Pipeline is enabled by default (set in extension.json)
$bsgTranslateTransferUsePipeline = true;  // in LocalSettings.php or settings.d/
```

When enabled, `Translator.php` delegates wikitext conversion to the new `WikitextTranslator` pipeline instead of the legacy `EscapeWikitext` + `TranslationWikitextConverter` approach.

---

## Architecture Diagram

See [TranslationPipeline.diagram.md](./TranslationPipeline.diagram.md) for the visual class relationship diagram.

---

## Pipeline Steps

### Step 1: Segmentation (`WikitextSegmenter`)

**Input:** Raw wikitext string
**Output:** `[$skeleton, $segments]` — a skeleton string with PUA markers + array of `Segment` objects

The segmenter processes wikitext line-by-line, classifying content as either:
- **Structural** (kept in skeleton) — table markup, heading markers `==`, list prefixes `*#;:`, opaque blocks (`<syntaxhighlight>`, `<math>`, etc.), redirects, behavior switches (`__TOC__`)
- **Translatable** (extracted as segments) — paragraph text, heading content, list item content, table cell content, gallery captions

Each translatable piece is replaced in the skeleton with a **PUA marker**: `\u{E000}PH_{id}\u{E001}` — Unicode Private Use Area characters that never appear in normal text.

#### Segment types

| Type | Source | Example |
|------|-------|---------|
| `heading` | `== Title ==` | "Title" (level stored separately) |
| `paragraph` | Consecutive non-structural lines | "Hello world. Second sentence." |
| `list-item` | `* Item text` | "Item text" |
| `table-cell` | `\| cell content` | "cell content" |
| `table-caption` | `\|+ caption` | "caption" |
| `gallery-caption` | `File:X.jpg\|Caption text` | "Caption text" |

#### Special handling

- **Multi-line templates**: Detected by unbalanced `{{`/`}}` braces in paragraph buffer. Lines accumulate until braces balance, preventing templates from being split across segments.
- **Nested tables**: Depth counter tracks `{|` / `|}` nesting. Inner table content becomes segments normally.
- **Gallery blocks**: `<gallery>` content is parsed line-by-line. The file reference stays in skeleton; captions (text after `|`) become segments.
- **Opaque block tags**: `<syntaxhighlight>`, `<math>`, `<poem>`, `<score>`, `<html>`, `<inputbox>`, `<categorytree>`, `<source>` — content passes through untranslated.

---

### Step 2: Translation (`SegmentTranslator` + `InlineConverter`)

**Input:** Array of `Segment` objects, source/target language codes
**Output:** Same segments with `translatedText` populated

For each segment:

1. **Outbound conversion** (`InlineConverter::wikitextToHtml`):
   - Templates `{{...}}` → opaque `<span>` placeholder (using brace-depth counter for nesting)
   - File links `[[File:...]]` → opaque `<span>` placeholder
   - Inline tags (`<code>`, `<nowiki>`, `<pre>`, `<ref>`, `<deepl:ignore>`, `<translation:ignore>`) → opaque placeholders
   - Bold `'''x'''` → `<b>x</b>`
   - Italic `''x''` → `<i>x</i>`
   - Internal links `[[Target|Label]]` → `<a data-link-id="N">Label</a>` (target stored in `linkMap`)
   - External links `[url label]` → `<a data-link-id="N">label</a>` (url stored in `linkMap`)
   - Special characters `&`, `<`, `>` → HTML entities

2. **DeepL API call** (batched):
   - Request body is JSON-encoded with `tag_handling: "html"`
   - Maximum 50 segments per request (API limit)
   - Maximum ~100KB payload per request (timeout prevention)
   - Glossary automatically attached if configured for target language

3. **Inbound conversion** (`InlineConverter::htmlToWikitext`):
   - `<b>`/`<strong>` → `'''...'''`
   - `<i>`/`<em>` → `''...''`
   - `<a data-link-id="N">Label</a>` → `[[Target|Label]]` (from `linkMap`)
   - `<span data-opaque-id="N"></span>` → original content (from `opaqueMap`)
   - HTML entities → decoded characters

#### File namespace prefixes

`InlineConverter` accepts a configurable list of file namespace prefixes (e.g., `['File', 'Image', 'Datei', 'Fichier']`) gathered dynamically from `NamespaceInfo` and `ContentLanguage` at service wiring time. This ensures all language-specific aliases for the File namespace are recognized without manual configuration.

---

### Step 3: Assembly (`SkeletonAssembler`)

**Input:** Skeleton string + array of translated segments
**Output:** Complete translated wikitext

Replaces each PUA marker `\u{E000}PH_{id}\u{E001}` in the skeleton with the corresponding segment's translated text. If a segment has no translation (DeepL failure), it falls back to the source text.

#### Safety check

If translated text contains PUA characters (indicating DeepL somehow returned marker-like content), the assembler falls back to source text and logs an error. This prevents cascading corruption.

---

### Step 4: Link Translation (`LinkTranslator`)

**Input:** Assembled wikitext
**Output:** Wikitext with translated links

Processes ALL `[[...]]` links in the final output via regex. Order matters:

1. **Categories** — always translated (NS + title), regardless of config
2. **Page titles** — translated when `translatePageTitle` is `true`
3. **Namespaces** — translated when `translateNamespaces` is `true`
4. **Gallery file namespaces** — translated when `translateNamespaces` is `true`
5. **File link labels** — always translated (captions in `[[File:...|...|Caption]]`)

#### Title translation

Uses `TitleDictionary` as cache (DB-backed). On cache miss, falls back to DeepL and stores the result. On dictionary insertion failure, produces `MissingDictionary/PrefixedTitle` as a visible error marker.

#### Namespace translation lookup order

1. `$bsgTranslateTransferTargetNamespaceMapping` (by NS name — exposed in Config Manager UI)
2. `DeeplTranslateConversionConfig.namespaceMap` (by NS ID — internal config)
3. Force English for `NS_FILE` / `NS_MEDIA`
4. MediaWiki `Language::getNsText()` fallback

#### Special cases

- **`NS_SPECIAL`**: Namespace is translated, but title is NOT (special page names may not have a DeepL-translatable equivalent)
- **`NS_TEMPLATE`**: Namespace is translated, but title is NOT (template names must match what exists on target wiki)
- **`NS_FILE` / `NS_MEDIA`**: Title is never translated (filenames are physical). NSFileRepo custom namespace prefixes inside filenames ARE translated.
- **`NS_CATEGORY`**: Skipped in title/namespace translation (already handled in step 1)
- **Semantic properties** (`[[Property::Value]]`): Skipped entirely
- **Interwiki links**: Skipped entirely
- **Anchor-only links** (`[[#Section]]`): Fragment is translated, link structure preserved

#### File link label translation

For links like `[[File:Photo.jpg|thumb|center|200px|A nice caption]]`, the pipeline identifies the label/caption (the last pipe-segment that isn't a dimension, key=value pair, or known image option) and translates it via DeepL.

#### NSFileRepo custom namespace translation

For file links like `[[File:CustomNS:MyFile.jpg|...]]`, the custom namespace prefix `CustomNS` is translated using the same namespace mapping lookup as regular namespaces.

---

### Step 5: Template Argument Translation (`TemplateTranslator`)

**Input:** Assembled wikitext
**Output:** Wikitext with translated template arguments

Only runs when `$bsgTranslateTransferTemplateArgs` registry is non-empty.

#### Registry format

```php
$bsgTranslateTransferTemplateArgs = [
    'Hint box' => [
        'text' => 'text',      // translate as prose via InlineConverter + DeepL
        'heading' => 'text',
    ],
    'ButtonLink' => [
        'title' => 'title',    // translate as wiki title via TitleDictionary
        'label' => 'text',
    ],
];
```

#### Tokenizer

A state machine with three states (`TEXT`, `TEMPLATE`, `INTERNAL_LINK`) properly handles:
- Nested templates (`{{Outer|arg={{Inner|val}}}}`)
- Internal links inside arguments (`{{T|page=[[Some Page]]}}`)
- Pipe characters inside nested structures (not misinterpreted as argument separators)

#### Translation types

- **`"text"`**: Segment goes through `InlineConverter::wikitextToHtml()` → DeepL → `InlineConverter::htmlToWikitext()`. Preserves wikitext formatting within the argument.
- **`"title"`**: Lookup in TitleDictionary first; DeepL fallback. Plain text, no formatting conversion.

---

### Step 6: Magic Word Translation (`MagicWordTranslator`)

**Input:** Assembled wikitext
**Output:** Wikitext with translated magic words (normalized to English)

Magic words are translated to **English** because English aliases are recognized by all MediaWiki installations.

#### Always executed (regardless of `translateMagicWords` config):

1. `{{#contentTranslate...}}` — removed entirely
2. `{{DISPLAYTITLE:value}}` — value translated via DeepL, magic word name normalized to English. Matches any language alias (e.g., `{{SEITENTITEL:...}}` on German wiki).

#### Gated by `translateMagicWords` config key:

3. **Double underscores** (`__TOC__`, `__NOTOC__`, `__FORCETOC__`, etc.) — replaced with English equivalents
4. **Variable magic words** (`{{PAGENAME}}`, `{{FULLPAGENAME}}`, `{{SITENAME}}`, etc.) — replaced with English. Actual templates are excluded by checking `Title::exists()` via TitleFactory.
5. **Image attributes** in `[[File:...]]` links — localized options like `miniatur`, `zentriert`, `rechts` are replaced with English `thumb`, `center`, `right` using `MagicWordFactory`.

---

## Configuration

See [Translation.MD](./Translation.MD) for the full configuration reference.

---

## Service Wiring

All pipeline services are wired in `includes/ServiceWiring.php` under the key `TranslationsTransferWikitextTranslator`:

```
WikitextTranslator
  ├── WikitextSegmenter
  ├── SegmentTranslator
  │     └── InlineConverter (with file NS prefixes from NamespaceInfo)
  ├── SkeletonAssembler
  ├── LinkTranslator (with conversionConfig, DeepL, TitleDictionary, TitleFactory, LanguageFactory, targetNamespaceMapping)
  ├── TemplateTranslator (with registry, DeepL, TitleDictionary) — only if registry non-empty
  └── MagicWordTranslator (with LanguageFactory, TitleFactory, DeepL, MagicWordFactory)
```

The `GlossaryDao` is used to attach the correct DeepL glossary ID for each target language.

---

## Comparison with Legacy Pipeline

| Aspect | Legacy (`EscapeWikitext`) | New Pipeline |
|--------|--------------------------|--------------|
| What DeepL sees | Full wikitext wrapped in `<deepl:ignore>` | Small inline HTML fragments only |
| Structure preservation | Fragile (relies on `ignore_tags`) | Lossless (skeleton never sent to DeepL) |
| Template handling | Entire template wrapped in ignore tags; args translated separately (beta feature) | Templates are opaque placeholders; registered args translated separately |
| Link translation | In pre/post processing | Dedicated post-assembly step |
| Magic words | In pre-processing | Dedicated post-assembly step |
| File link labels | Wrapped non-option parts left outside `<deepl:ignore>`, sent to DeepL with rest of page | Post-assembly dedicated label extraction and separate DeepL call |
| Extensibility | Monolithic class | Modular pipeline with independent steps |
| Error isolation | One DeepL failure corrupts full page | Per-segment fallback to source text |

---

## Error Handling

- **DeepL API failure**: Individual batch falls back to source text for all segments. Other batches unaffected.
- **PUA marker in translated text**: Segment falls back to source text (logged as error).
- **Invalid title in link**: Link skipped, warning logged.
- **TitleDictionary insert failure**: Link becomes `MissingDictionary/PrefixedTitle` (visible error marker).
- **Empty segments**: Returned as-is (no API call).

---

## Future Work

- **Positional template argument translation** — currently only named args are supported
