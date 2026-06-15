# Translation Pipeline — Class Diagram

> **Viewing Mermaid diagrams:**
> - **GitHub**: renders Mermaid blocks natively in `.md` file preview
> - **PHPStorm**: install the "Mermaid" plugin (Settings → Plugins → search "Mermaid")
> - **Obsidian**: Mermaid is supported out of the box (no plugin needed)
> - **VS Code**: install "Markdown Preview Mermaid Support" extension
>
> Alternatively, see the **ASCII Data Flow** diagram below — it requires no plugin.

## Pipeline Flow (Mermaid)

```mermaid
flowchart TD
    subgraph Input
        A[Raw Wikitext]
    end

    subgraph "Step 1: Segmentation"
        B[WikitextSegmenter]
        B -->|skeleton| C[Skeleton string with PUA markers]
        B -->|segments| D["Segment[] array"]
    end

    subgraph "Step 2: Translation"
        E[InlineConverter]
        F[SegmentTranslator]
        E -->|wikitextToHtml| F
        F -->|DeepL API| G[Translated HTML]
        G -->|htmlToWikitext| E
    end

    subgraph "Step 3: Assembly"
        H[SkeletonAssembler]
    end

    subgraph "Step 4–6: Post-Processing"
        I[LinkTranslator]
        J[TemplateTranslator]
        K[MagicWordTranslator]
    end

    subgraph Output
        L[Translated Wikitext]
    end

    A --> B
    D --> E
    C --> H
    E --> H
    H --> I
    I --> J
    J --> K
    K --> L
```

## Class Relationships

```mermaid
classDiagram
    class WikitextTranslator {
        -WikitextSegmenter segmenter
        -SegmentTranslator translator
        -SkeletonAssembler assembler
        -LinkTranslator linkTranslator
        -TemplateTranslator templateTranslator
        -MagicWordTranslator magicWordTranslator
        +translate(wikitext, sourceLang, targetLang) string
    }

    class WikitextSegmenter {
        +segment(wikitext) [skeleton, Segment[]]
        -processLine(line)
        -flushParagraph()
        -processGalleryLine(line)
    }

    class Segment {
        +string id
        +string type
        +string text
        +string translatedText
        +int headingLevel
    }

    class InlineConverter {
        -string[] fileNamespacePrefixes
        -array linkMap
        -array opaqueMap
        +wikitextToHtml(wikitext) string
        +htmlToWikitext(html) string
    }

    class SegmentTranslator {
        -InlineConverter converter
        -DeepLTranslator deepL
        +translateSegments(Segment[], sourceLang, targetLang)
    }

    class SkeletonAssembler {
        +assemble(skeleton, Segment[]) string
    }

    class LinkTranslator {
        -Config conversionConfig
        -DeepLTranslator deepL
        -IDictionary titleDictionary
        -TitleFactory titleFactory
        -LanguageFactory languageFactory
        -array targetNamespaceMapping
        +translateLinks(wikitext, sourceLang, targetLang) string
        -translateCategories(wikitext)
        -translateTitles(wikitext)
        -translateNamespaces(wikitext)
        -translateGalleryNs(wikitext)
        -translateFileLinkLabels(wikitext)
        -getNsText(nsId, targetLang)
    }

    class TemplateTranslator {
        -array registry
        -DeepLTranslator deepL
        -IDictionary titleDictionary
        +translateTemplates(wikitext, sourceLang, targetLang) string
        -tokenize(wikitext) Token[]
        -translateArg(value, type)
    }

    class MagicWordTranslator {
        -LanguageFactory languageFactory
        -TitleFactory titleFactory
        -DeepLTranslator deepL
        -MagicWordFactory magicWordFactory
        -bool enabled
        +translateMagicWords(wikitext, sourceLang, targetLang) string
        -removeContentTranslate(wikitext)
        -translateDisplayTitle(wikitext)
        -translateDoubleUnderscores(wikitext)
        -translateVariableMagicWords(wikitext)
        -translateImageAttributes(wikitext)
    }

    WikitextTranslator --> WikitextSegmenter
    WikitextTranslator --> SegmentTranslator
    WikitextTranslator --> SkeletonAssembler
    WikitextTranslator --> LinkTranslator
    WikitextTranslator --> TemplateTranslator
    WikitextTranslator --> MagicWordTranslator
    SegmentTranslator --> InlineConverter
    WikitextSegmenter ..> Segment : creates
    SkeletonAssembler ..> Segment : reads
```

## Data Flow (ASCII)

```
┌───────────────────────────────────────────────────────────────────────┐
│                         Raw Wikitext Input                            │
└──────────────────────────────────┬────────────────────────────────────┘
                                   │
                                   ▼
┌───────────────────────────────────────────────────────────────────────┐
│  STEP 1: WikitextSegmenter                                            │
│                                                                       │
│  Line-by-line parser. Outputs:                                        │
│    • Skeleton: structural wikitext with PUA markers                   │
│    • Segments: translatable text pieces                               │
│                                                                       │
│  Skeleton output:           Segments output:                          │
│    == ␀PH_1␁ ==              Segment{id:1, text:"Title", heading}     │
│    ␀PH_2␁                    Segment{id:2, text:"Paragraph", para}    │
│    {| class="wikitable"      Segment{id:3, text:"Cell", cell}         │
│    | ␀PH_3␁                                                           │
│    |}                                                                 │
└──────────┬────────────────────────────────┬───────────────────────────┘
           │ skeleton                       │ segments
           │                                ▼
           │        ┌───────────────────────────────────────────────────┐
           │        │  STEP 2: SegmentTranslator                        │
           │        │                                                   │
           │        │  For each segment:                                │
           │        │    InlineConverter.wikitextToHtml()               │
           │        │      "'''bold''' text" → "<b>bold</b> text"       │
           │        │    DeepL API (batched, JSON body)                 │
           │        │      "<b>bold</b> text" → "<b>fett</b> Text"      │
           │        │    InlineConverter.htmlToWikitext()               │
           │        │      "<b>fett</b> Text" → "'''fett''' Text"       │
           │        └───────────────────────┬───────────────────────────┘
           │                                │ translated segments
           ▼                                ▼
┌───────────────────────────────────────────────────────────────────────┐
│  STEP 3: SkeletonAssembler                                            │
│                                                                       │
│  Replaces PUA markers with translated text:                           │
│    == '''fett''' Text ==                                              │
│    Übersetzter Absatz                                                 │
│    {| class="wikitable"                                               │
│    | Zelle                                                            │
│    |}                                                                 │
└──────────────────────────────────┬────────────────────────────────────┘
                                   │
                                   ▼
┌───────────────────────────────────────────────────────────────────────┐
│  STEP 4: LinkTranslator                                               │
│                                                                       │
│  Regex-based [[...]] processing:                                      │
│    [[Category:Cats]] → [[Kategorie:Katzen]]                           │
│    [[Help:FAQ]]      → [[Hilfe:FAQ_DE]]                               │
│    [[File:X.jpg|thumb|Nice photo]] → [[Datei:X.jpg|thumb|Foto]]       │
└──────────────────────────────────┬────────────────────────────────────┘
                                   │
                                   ▼
┌───────────────────────────────────────────────────────────────────────┐
│  STEP 5: TemplateTranslator                                           │
│                                                                       │
│  State-machine tokenizer finds templates in registry:                 │
│    {{Hint box|text=Click here}} → {{Hint box|text=Hier klicken}}      │
│    {{ButtonLink|title=Main Page}} → {{ButtonLink|title=Hauptseite}}   │
└──────────────────────────────────┬────────────────────────────────────┘
                                   │
                                   ▼
┌───────────────────────────────────────────────────────────────────────┐
│  STEP 6: MagicWordTranslator                                          │
│                                                                       │
│  Normalizes magic words to English:                                   │
│    __INHALTSVERZEICHNIS__ → __TOC__                                   │
│    {{SEITENNAME}}         → {{PAGENAME}}                              │
│    {{SEITENTITEL:Titel}}  → {{DISPLAYTITLE:Translated Title}}         │
│    [[Datei:X.jpg|miniatur|zentriert]] →                               │
│         [[Datei:X.jpg|thumb|center]]                                  │
└──────────────────────────────────┬────────────────────────────────────┘
                                   │
                                   ▼
┌───────────────────────────────────────────────────────────────────────┐
│                        Translated Wikitext Output                     │
└───────────────────────────────────────────────────────────────────────┘
```

## Segment Extraction Example

Input wikitext:
```
== Introduction ==
This is a '''paragraph''' with [[a link]].

{| class="wikitable"
| Cell one
| Cell two
|}
```

After segmentation:

**Skeleton:**
```
== ␀PH_1␁ ==
␀PH_2␁

{| class="wikitable"
| ␀PH_3␁
| ␀PH_4␁
|}
```

**Segments:**
```
[0] {id: "1", type: "heading", level: 2, text: "Introduction"}
[1] {id: "2", type: "paragraph", text: "This is a '''paragraph''' with [[a link]]."}
[2] {id: "3", type: "table-cell", text: "Cell one"}
[3] {id: "4", type: "table-cell", text: "Cell two"}
```
