<?php

namespace BlueSpice\TranslationTransfer\Tests;

use BlueSpice\TranslationTransfer\EscapeWikitext;
use MediaWiki\Language\Language;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueSpice\TranslationTransfer\EscapeWikitext
 */
class EscapeWikitextTest extends TestCase {

	/**
	 * @param string $wikitext
	 * @param string $expectedEscapedWikitext
	 *
	 * @covers \BlueSpice\TranslationTransfer\EscapeWikitext::process
	 *
	 * @dataProvider processDataProvider
	 */
	public function testProcess( string $wikitext, string $expectedEscapedWikitext ): void {
		$contentLanguageMock = $this->createMock( Language::class );
		$contentLanguageMock->method( 'getMagicWords' )->willReturn( [
			'img_thumbnail' => [
				1, 'thumb', 'miniatur'
			],
			'img_alt' => [
				1, 'alternativtext=$1', 'alt=$1'
			],
			'img_none' => [
				1, 'ohne', 'none'
			]
		] );

		$englishLanguageMock = $this->createMock( Language::class );
		$englishLanguageMock->method( 'getMagicWords' )->willReturn( [
			'img_thumbnail' => [
				1, 'thumb'
			],
			'img_none' => [
				1, 'none'
			]
		] );

		$targetLang = 'en';

		$escapeWikitext = new EscapeWikitext( $wikitext, $englishLanguageMock, $contentLanguageMock, $targetLang );
		$escapeWikitext->process();

		$actualEscapedWikitext = $escapeWikitext->getResultWikitext();

		$this->assertEquals( $expectedEscapedWikitext, $actualEscapedWikitext );
	}

	/**
	 * @return array
	 */
	public function processDataProvider(): array {
		return [
			'Internal links' => [
				'[[SomePage|label for that link]]  [[SomePage2|label for that link 2]] [[SomePage3]] [[Wikipedia:Verifiability|]]',
				'<deepl:ignore>[[SomePage|</deepl:ignore>label for that link<deepl:ignore>]]</deepl:ignore>  <deepl:ignore>[[SomePage2|</deepl:ignore>label for that link 2<deepl:ignore>]]</deepl:ignore> <deepl:ignore>[[SomePage3]]</deepl:ignore> <deepl:ignore>[[Wikipedia:Verifiability|</deepl:ignore><deepl:ignore>]]</deepl:ignore>',
			],

			'External links' => [
				'[https://google.com.ua label for that external link]',
				'<deepl:ignore>[https://google.com.ua </deepl:ignore>label for that external link<deepl:ignore>]</deepl:ignore>'
			],

			'Both internal and external links' => [
				'[https://google.com.ua label for that external link] [[SomePage|label for that link]]
				[https://somesite.com label for another external link] [[SomePage2|label for that link 2]]',
				'<deepl:ignore>[https://google.com.ua </deepl:ignore>label for that external link<deepl:ignore>]</deepl:ignore> <deepl:ignore>[[SomePage|</deepl:ignore>label for that link<deepl:ignore>]]</deepl:ignore>
				<deepl:ignore>[https://somesite.com </deepl:ignore>label for another external link<deepl:ignore>]</deepl:ignore> <deepl:ignore>[[SomePage2|</deepl:ignore>label for that link 2<deepl:ignore>]]</deepl:ignore>',
			],

			'Tables' => [
				<<<HERE
{| class="wikitable" style="margin:auto"
|+ Caption text
|-
! Header text !! Header text !! Header text
|-
| Example || Example || Example
|-
| Example || Example || Example
|-
| Example || Example || Example
|}

{|
|Orange
|Apple
|-
|Bread
|Pie
|-
|Butter
|Ice cream
|}
HERE
,
				<<<HERE
<deepl:ignore>{| class="wikitable" style="margin:auto"</deepl:ignore>
<deepl:ignore>|+</deepl:ignore> Caption text
<deepl:ignore>|-</deepl:ignore>
<deepl:ignore>!</deepl:ignore> Header text <deepl:ignore>!!</deepl:ignore> Header text <deepl:ignore>!!</deepl:ignore> Header text
<deepl:ignore>|-</deepl:ignore>
<deepl:ignore>|</deepl:ignore> Example <deepl:ignore>||</deepl:ignore> Example <deepl:ignore>||</deepl:ignore> Example
<deepl:ignore>|-</deepl:ignore>
<deepl:ignore>|</deepl:ignore> Example <deepl:ignore>||</deepl:ignore> Example <deepl:ignore>||</deepl:ignore> Example
<deepl:ignore>|-</deepl:ignore>
<deepl:ignore>|</deepl:ignore> Example <deepl:ignore>||</deepl:ignore> Example <deepl:ignore>||</deepl:ignore> Example
<deepl:ignore>|}</deepl:ignore>

<deepl:ignore>{|</deepl:ignore>
<deepl:ignore>|</deepl:ignore>Orange
<deepl:ignore>|</deepl:ignore>Apple
<deepl:ignore>|-</deepl:ignore>
<deepl:ignore>|</deepl:ignore>Bread
<deepl:ignore>|</deepl:ignore>Pie
<deepl:ignore>|-</deepl:ignore>
<deepl:ignore>|</deepl:ignore>Butter
<deepl:ignore>|</deepl:ignore>Ice cream
<deepl:ignore>|}</deepl:ignore>
HERE
			],

			'Inline formatting (bold and italic)' => [
				"''italic text'' '''bold text''' '''''bold & italic text''''' '''another bold text'''",
				"<deepl:ignore>''</deepl:ignore>italic text<deepl:ignore>''</deepl:ignore> <deepl:ignore>'''</deepl:ignore>bold text<deepl:ignore>'''</deepl:ignore> <deepl:ignore>'''''</deepl:ignore>bold & italic text<deepl:ignore>'''''</deepl:ignore> <deepl:ignore>'''</deepl:ignore>another bold text<deepl:ignore>'''</deepl:ignore>"
			],

			'Inline formatting (headings)' => [
				<<<HERE
== '''2004-2005''' ==

== Level 2 ==

=== Level 3 ===

==== Level 4 ====

===== Level 5 =====

====== Level 6 ======

some text ==== not heading because not in the start of the line ====
HERE
,
				<<<HERE
<deepl:ignore>==</deepl:ignore> <deepl:ignore>'''</deepl:ignore>2004-2005<deepl:ignore>'''</deepl:ignore> <deepl:ignore>==</deepl:ignore>

<deepl:ignore>==</deepl:ignore> Level 2 <deepl:ignore>==</deepl:ignore>

<deepl:ignore>===</deepl:ignore> Level 3 <deepl:ignore>===</deepl:ignore>

<deepl:ignore>====</deepl:ignore> Level 4 <deepl:ignore>====</deepl:ignore>

<deepl:ignore>=====</deepl:ignore> Level 5 <deepl:ignore>=====</deepl:ignore>

<deepl:ignore>======</deepl:ignore> Level 6 <deepl:ignore>======</deepl:ignore>

some text ==== not heading because not in the start of the line ====
HERE
			],

			'Redirects' => [
				<<<HERE
#REDIRECT [[SomePage]]
#Redirect [[SomePage]]
#redirect [[SomePage2]]
HERE
,
				// Internal page links are also escaped, that's totally okay
				<<<HERE
<deepl:ignore>#REDIRECT</deepl:ignore> <deepl:ignore>[[SomePage]]</deepl:ignore>
<deepl:ignore>#REDIRECT</deepl:ignore> <deepl:ignore>[[SomePage]]</deepl:ignore>
<deepl:ignore>#REDIRECT</deepl:ignore> <deepl:ignore>[[SomePage2]]</deepl:ignore>
HERE
			],

			'Semantic properties links' => [
				<<<HERE
[[Example property::link]]

[[Example property::link|alternate text]]

[[Example property::link| ]]
HERE
,
				<<<HERE
<deepl:ignore>[[Example property::link]]</deepl:ignore>

<deepl:ignore>[[Example property::link|</deepl:ignore>alternate text<deepl:ignore>]]</deepl:ignore>

<deepl:ignore>[[Example property::link| ]]</deepl:ignore>
HERE
			],

			'Files links' => [
				<<<HERE
[[File:Some file.png|Some label]]

[[:File:Some file.pdf|Some label]]

[[Media:Some file.png|Some label]]

[[File:Some file.png|alternativtext=Alternative text|thumb|200x200px|Some label]]

[[File:MS:My file version 2.0.pdf|ohne|miniatur]]
HERE
,
				<<<HERE
<deepl:ignore>[[File:Some file.png|</deepl:ignore>Some label<deepl:ignore>]]</deepl:ignore>

<deepl:ignore>[[:File:Some file.pdf|</deepl:ignore>Some label<deepl:ignore>]]</deepl:ignore>

<deepl:ignore>[[Media:Some file.png|</deepl:ignore>Some label<deepl:ignore>]]</deepl:ignore>

<deepl:ignore>[[File:Some file.png|thumb|200x200px|</deepl:ignore><deepl:ignore>alt=</deepl:ignore>Alternative text<deepl:ignore>|</deepl:ignore>Some label<deepl:ignore>]]</deepl:ignore>

<deepl:ignore>[[File:MS:My file version 2.0.pdf|none|thumb]]</deepl:ignore>
HERE
			],

			'Lists' => [
				<<<HERE
Examples from here:
https://www.mediawiki.org/wiki/Help:Lists

* Lists are easy to do:
** start every line
* with a star
** more stars mean
*** deeper levels

* A newline
* in a list
marks the end of the list.
Of course
* you can
* start again.

# Numbered lists are good
## very organized
## easy to follow

; Description or definition lists
; term : definition
; semicolon plus term
: colon plus definition

; Mixed definition lists
; item 1 : definition
:; sub-item 1 plus term
:: two colons plus definition
:; sub-item 2 : colon plus definition
; item 2
: back to the main list

* Or create mixed lists
*# and nest them
*#* like this
*#*; definitions
*#*: work:
*#*; apple
*#*; banana
*#*: fruits


#list item A1
##list item B1
###list item C1
###list item C2
##list item B2
#list item A2
HERE
,
				<<<HERE
Examples from here:
https://www.mediawiki.org/wiki/Help:Lists

<deepl:ignore>*</deepl:ignore> Lists are easy to do:
<deepl:ignore>**</deepl:ignore> start every line
<deepl:ignore>*</deepl:ignore> with a star
<deepl:ignore>**</deepl:ignore> more stars mean
<deepl:ignore>***</deepl:ignore> deeper levels

<deepl:ignore>*</deepl:ignore> A newline
<deepl:ignore>*</deepl:ignore> in a list
marks the end of the list.
Of course
<deepl:ignore>*</deepl:ignore> you can
<deepl:ignore>*</deepl:ignore> start again.

<deepl:ignore>#</deepl:ignore> Numbered lists are good
<deepl:ignore>##</deepl:ignore> very organized
<deepl:ignore>##</deepl:ignore> easy to follow

<deepl:ignore>;</deepl:ignore> Description or definition lists
<deepl:ignore>;</deepl:ignore> term : definition
<deepl:ignore>;</deepl:ignore> semicolon plus term
<deepl:ignore>:</deepl:ignore> colon plus definition

<deepl:ignore>;</deepl:ignore> Mixed definition lists
<deepl:ignore>;</deepl:ignore> item 1 : definition
<deepl:ignore>:;</deepl:ignore> sub-item 1 plus term
<deepl:ignore>::</deepl:ignore> two colons plus definition
<deepl:ignore>:;</deepl:ignore> sub-item 2 : colon plus definition
<deepl:ignore>;</deepl:ignore> item 2
<deepl:ignore>:</deepl:ignore> back to the main list

<deepl:ignore>*</deepl:ignore> Or create mixed lists
<deepl:ignore>*#</deepl:ignore> and nest them
<deepl:ignore>*#*</deepl:ignore> like this
<deepl:ignore>*#*;</deepl:ignore> definitions
<deepl:ignore>*#*:</deepl:ignore> work:
<deepl:ignore>*#*;</deepl:ignore> apple
<deepl:ignore>*#*;</deepl:ignore> banana
<deepl:ignore>*#*:</deepl:ignore> fruits


<deepl:ignore>#</deepl:ignore>list item A1
<deepl:ignore>##</deepl:ignore>list item B1
<deepl:ignore>###</deepl:ignore>list item C1
<deepl:ignore>###</deepl:ignore>list item C2
<deepl:ignore>##</deepl:ignore>list item B2
<deepl:ignore>#</deepl:ignore>list item A2
HERE
			],
			'Gallery' => [
				<<<HERE
<gallery>
File:IMG 1111.JPEG|Some description here, (smth) + random
File:IMG 3333.JPG|Some description here, with [[Internal link|link label]]
File:Image 22.jpg|Description
File:Image 33.jpg|Description with ©Smth and break line<br />
File:Image 9999.jpg|[[Some page]] anything<br />
File:Another image.JPG|Description with break line<br /> and ''bold text''
File:Image without description.JPG
</gallery>
HERE
,
				<<<HERE
<gallery>
<deepl:ignore>File:IMG 1111.JPEG|</deepl:ignore>Some description here, (smth) + random
<deepl:ignore>File:IMG 3333.JPG|</deepl:ignore>Some description here, with <deepl:ignore>[[Internal link|</deepl:ignore>link label<deepl:ignore>]]</deepl:ignore>
<deepl:ignore>File:Image 22.jpg|</deepl:ignore>Description
<deepl:ignore>File:Image 33.jpg|</deepl:ignore>Description with ©Smth and break line<br />
<deepl:ignore>File:Image 9999.jpg|</deepl:ignore><deepl:ignore>[[Some page]]</deepl:ignore> anything<br />
<deepl:ignore>File:Another image.JPG|</deepl:ignore>Description with break line<br /> and <deepl:ignore>''</deepl:ignore>bold text<deepl:ignore>''</deepl:ignore>
<deepl:ignore>File:Image without description.JPG</deepl:ignore>
</gallery>
HERE
			]
		];
	}
}
