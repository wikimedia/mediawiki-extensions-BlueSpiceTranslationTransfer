# TranslationTransfer

## Installation
Execute

    composer require hallowelt/translationtransfer dev-REL1_31
within MediaWiki root or add `hallowelt/translationtransfer` to the
`composer.json` file of your project

## Activation
Add

    wfLoadExtension( 'TranslationTransfer' );
to your `LocalSettings.php` or the appropriate `settings.d/` file.