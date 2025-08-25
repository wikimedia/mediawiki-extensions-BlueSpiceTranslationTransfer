# Workflow
This functionality is meant to translate a page to other languages on other BlueSpice wikis.

# Requirements
## Extensions
* BlueSpiceTranslationTransfer
* ContentTransfer (on all instances)
* BlueSpiceVisualEditorConnector (and running parsoid)
* MergeArticles (on all instances)

## Shared table
For correct work of translation workflow it needs `bs_translationtransfer_translations` table to be shared one.
For that, such code should be added to each of wiki instances (except root one) in the `LocalSettings.php`:

	$wgSharedDB = '<database_used_in_root_wiki_instance>';

	$wgSharedTables = [ 'bs_translationtransfer_translations' ];

In case if some shared tables configuration already takes place (for example, `user` table is shared among wiki instances), then `bs_translationtransfer_translations` should be appended like that:

	$wgSharedTables[] = 'bs_translationtransfer_translations';


## Configuration
- `$mwsgDeeplTranslateServiceAuth` - auth token - must be set!
- `$mwsgDeeplTranslateServiceUrl` - DeepL URL

- `$bsgDeeplTranslateConversionConfig` - configuration for the translation behaviour. Defaults are set, no need to change anything unless needed

  `translatePageTitle` - default `false` -  whether to translate page title. If false, page name at target will be the same as origin

  `addDisplayTitleToContent` - default `true` - whether to add translated page title to the content of the page, as DISPLAYTITLE. Only evaluated if the page name is not translated

  `translateNamespaces` - default `true` - whether to translate namespace names in internal links to target wiki's language

  `namespaceMap` - default `[]` - used only if `translateNamespaces` is `true`. Mapping for custom namespace names to be translated. eg:

        [ 1504 => [ 'fr' => 'Livre', 'it' => 'Libro' ]

  This is the primary authority, so it can be used to override system namespace names as well.

  `translateMagicWords` - default `true` - whether to translate magic words, eg `__KEININHALTVERZEICHNIS__ => __NOTOC__`. This also applies to image attributes, eg. `gerahmt => framed`. All magic words are translated to English instead of target language, to minimize errors, since English is understood by all wikis.

- `$bsgTranslateTransferFilesToDraft` - If true, files will be included in the review process (pushed to draft NS first)
- `$bsgTranslateTransferTargets` - Map of language and target wikis. Key is the language code, and value an array with such structure:
		[ 'key' => <target_key>, 'url' => <client_url>, 'pushToDraft' => 'regular', 'draftNamespace' => 'Draft' ]
  Where value of "key" is target wiki ID as set in ContentTransfer configuration, and value of "url" is so called "client URL" - base URL for article.
  For example, if URL of "Main Page" is `http://localhost/de/wiki/Main_Page`, then "client URL" will be `http://localhost/de/wiki`.
  If no "client URL" is set - then there will be fallback to target API url from ContentTransfer extension configuration.

  And there are two optional keys, "pushToDraft" and "draftNamespace".
	"pushToDraft" - whether we need to push page to the "draftNamespace" after translation. Possible values:
  	  * "regular" - page is pushed directly on the first translation, and to the "draftNamespace" on all next translations.
      * "always" - page is always pushed to the "draftNamespace" after translation.

	"draftNamespace" - name of "draft" namespace where pages will be transferred to. They can then be merged to original destination using "Special:MergeArticles" UI.

  If "pushToDraft" is not net - pages are always pushed directly.


- `$bsgTranslateTransferNamespaces` - Map of language and available for outgoing translation namespaces. Example:


	  $bsgTranslateTransferNamespaces = [
		  'en' => [
			  10, 0 // NS_TEMPLATE and NS_MAIN
		  ],
		  'de' => [
			  0 // NS_MAIN
		  ]
	  ];

- `$bsgTranslateTransferLeadingLanguage` - lowercase language code of the "leading" wiki language - source language for all outgoing translations. Therefore "Special:TranslationDictionary" and "Special:TranslationGlossary" special pages will be available only on wiki instance with the same content language as leading one.

	In BlueSpiceWikiFarm, by default: language of "root" wiki ( "w" instance).

	In cases with wiki farms with multiple source languages set this variable to "false":
		`$bsgTranslateTransferLeadingLanguage = false;`

### ContentTransfer configuration
Targets for the translation transfer need to be configured as usual ContentTransfer targets (see that extensions docs)
As mentioned before, all wiki targets that are included in translation and transfer process must be mapped `$bsgTranslateTransferTargets`

## Notes
- Any transcluded content (from templates) will NOT be translated as it's not a part of the page
- NS_FILE will always be translated to English, to avoid issues with image attributes
- All "ContentTransfer" and "MergeArticles" configuration should be located in the root wiki's `LocalSettings.php` file or any `settings.d` settings files, as well as "BlueSpiceTranslationTransfer" configuration

## Translation overview
Translation overview is available at "Special:TranslationOverview" special page.

# LocalTranslations
As a bonus feature, pages can be configured to translate to configured languages on the wiki itself, without transferring anywhere.

To enable this, add parser function `{{#contentTranslate:lang,codes}}` to the page you want to offer translations on.
It accepts only one param: comma-separated list of language codes in which to offer translations. This param is optional, if not set, all languages
from `$bsgTranslateTransferTargets` will be offered. This list must be a subset of that mapping.

# Block files transfer
If one wants to block transferring of related files when translating specific page - magic word "__BS_NO_AUTOMATIC_DOCUMENT_TRANSLATION__" can be added to page content for that.
