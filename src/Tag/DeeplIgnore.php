<?php

namespace BlueSpice\TranslationTransfer\Tag;

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\GenericTagHandler\ClientTagSpecification;
use MWStake\MediaWiki\Component\GenericTagHandler\GenericTag;
use MWStake\MediaWiki\Component\GenericTagHandler\ITagHandler;

class DeeplIgnore extends GenericTag {
	/**
	 * @inheritDoc
	 */
	public function getTagNames(): array {
		return [ 'deepl:ignore', 'translation:ignore' ];
	}

	/**
	 * @return bool
	 */
	public function hasContent(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function shouldParseInput(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getContainerElementName(): ?string {
		return 'span';
	}

	/**
	 * @inheritDoc
	 */
	public function getHandler( MediaWikiServices $services ): ITagHandler {
		return new DeeplIgnoreHandler();
	}

	/**
	 * @inheritDoc
	 */
	public function getParamDefinition(): ?array {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getClientTagSpecification(): ClientTagSpecification|null {
		return null;
	}
}
