<?php
/**
 * Performs transformations of HTML by wrapping around libxml2 and working
 * around its countless bugs.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace HtmlFormatter;

class HtmlFormatter {
	/**
	 * @var \DOMDocument
	 */
	private $doc;

	/**
	 * @var string
	 */
	private $html;

	/**
	 * @var string[]
	 */
	private $itemsToRemove = [];

	/**
	 * @var string[]
	 */
	private $elementsToFlatten = [];

	/**
	 * Whether a libxml_disable_entity_loader() call is needed
	 */
	private const DISABLE_LOADER = LIBXML_VERSION < 20900;

	/**
	 * @var bool
	 */
	protected $removeMedia = false;

	/**
	 * @param string $html Text to process
	 */
	public function __construct( $html ) {
		$this->html = $html;
	}

	/**
	 * Turns a chunk of HTML into a proper document
	 * @param string $html HTML to wrap
	 * @return string
	 */
	public static function wrapHTML( $html ) {
		return '<!doctype html><html><head></head><body>' . $html . '</body></html>';
	}

	/**
	 * Override this in descendant class to modify HTML after it has been converted from DOM tree
	 * @param string $html HTML to process
	 * @return string Processed HTML
	 */
	protected function onHtmlReady( $html ) {
		return $html;
	}

	/**
	 * @return \DOMDocument DOM to manipulate
	 */
	public function getDoc() {
		if ( !$this->doc ) {
			// DOMDocument::loadHTML apparently isn't very good with encodings, so
			// convert input to ASCII by encoding everything above 128 as entities.
			$html = \mb_convert_encoding( $this->html, 'HTML-ENTITIES', 'UTF-8' );

			// Workaround for bug that caused spaces before references
			// to disappear during processing: https://phabricator.wikimedia.org/T55086
			$html = str_replace( ' <', '&#32;<', $html );

			\libxml_use_internal_errors( true );
			if ( self::DISABLE_LOADER ) {
				$loader = \libxml_disable_entity_loader();
			}
			$this->doc = new \DOMDocument();
			$this->doc->strictErrorChecking = false;
			$this->doc->loadHTML( $html );
			if ( self::DISABLE_LOADER ) {
				\libxml_disable_entity_loader( $loader );
			}
			\libxml_use_internal_errors( false );
			$this->doc->encoding = 'UTF-8';
		}
		return $this->doc;
	}

	/**
	 * Sets whether images/videos/sounds should be removed from output
	 * @param bool $flag Whether to remove or not
	 */
	public function setRemoveMedia( $flag = true ) {
		$this->removeMedia = $flag;
	}

	/**
	 * Adds one or more selector of content to remove. A subset of CSS selector
	 * syntax is supported:
	 *
	 *   <tag>
	 *   <tag>.class
	 *   .<class>
	 *   #<id>
	 *
	 * @param string[]|string $selectors Selector(s) of stuff to remove
	 */
	public function remove( $selectors ) {
		$this->itemsToRemove = array_merge( $this->itemsToRemove, (array)$selectors );
	}

	/**
	 * Adds one or more element name to the list to flatten (remove tag, but not its content)
	 * Can accept undelimited regexes
	 *
	 * Note this interface may fail in surprising unexpected ways due to usage of regexes,
	 * so should not be relied on for HTML markup security measures.
	 *
	 * @param string[]|string $elements Name(s) of tag(s) to flatten
	 */
	public function flatten( $elements ) {
		$this->elementsToFlatten = array_merge( $this->elementsToFlatten, (array)$elements );
	}

	/**
	 * Instructs the formatter to flatten all tags
	 */
	public function flattenAllTags() {
		$this->flatten( '[?!]?[a-z0-9]+' );
	}

	/**
	 * Removes content we've chosen to remove.  The text of the removed elements can be
	 * extracted with the getText method.
	 * @return \DOMElement[] Array of removed DOMElements
	 */
	public function filterContent() {
		$removals = $this->parseItemsToRemove();

		// Bail out early if nothing to do
		if ( \array_reduce( $removals,
			function ( $carry, $item ) {
				return $carry && !$item;
			},
			true
		) ) {
			return [];
		}

		$doc = $this->getDoc();

		// Remove tags

		// You can't remove DOMNodes from a DOMNodeList as you're iterating
		// over them in a foreach loop. It will seemingly leave the internal
		// iterator on the foreach out of wack and results will be quite
		// strange. Though, making a queue of items to remove seems to work.
		$domElemsToRemove = [];
		foreach ( $removals['TAG'] as $tagToRemove ) {
			$tagToRemoveNodes = $doc->getElementsByTagName( $tagToRemove );
			foreach ( $tagToRemoveNodes as $tagToRemoveNode ) {
				if ( $tagToRemoveNode ) {
					$domElemsToRemove[] = $tagToRemoveNode;
				}
			}
		}
		$removed = $this->removeElements( $domElemsToRemove );

		// Elements with named IDs
		$domElemsToRemove = [];
		foreach ( $removals['ID'] as $itemToRemove ) {
			$itemToRemoveNode = $doc->getElementById( $itemToRemove );
			if ( $itemToRemoveNode ) {
				$domElemsToRemove[] = $itemToRemoveNode;
			}
		}
		$removed = array_merge( $removed, $this->removeElements( $domElemsToRemove ) );

		// CSS Classes
		$domElemsToRemove = [];
		$xpath = new \DOMXPath( $doc );
		foreach ( $removals['CLASS'] as $classToRemove ) {
			// Use spaces to avoid matching for unrelated classnames (T231160)
			// https://stackoverflow.com/a/1604480/319266
			$elements = $xpath->query( '//*[contains(concat(" ", @class, " "), " ' . $classToRemove . ' ")]' );

			/** @var $element \DOMElement */
			foreach ( $elements as $element ) {
				$classes = $element->getAttribute( 'class' );
				if ( \preg_match( "/\b$classToRemove\b/", $classes ) && $element->parentNode ) {
					$domElemsToRemove[] = $element;
				}
			}
		}
		$removed = \array_merge( $removed, $this->removeElements( $domElemsToRemove ) );

		// Tags with CSS Classes
		foreach ( $removals['TAG_CLASS'] as $classToRemove ) {
			$parts = explode( '.', $classToRemove );

			$elements = $xpath->query(
				'//' . $parts[0] . '[@class="' . $parts[1] . '"]'
			);
			$removed = array_merge( $removed, $this->removeElements( $elements ) );
		}

		return $removed;
	}

	/**
	 * Removes a list of elelments from DOMDocument
	 * @param \DOMElement[]|\DOMNodeList $elements
	 * @return \DOMElement[] Array of removed elements
	 */
	private function removeElements( $elements ) {
		$list = $elements;
		if ( $elements instanceof \DOMNodeList ) {
			$list = [];
			foreach ( $elements as $element ) {
				$list[] = $element;
			}
		}
		/** @var $element \DOMElement */
		foreach ( $list as $element ) {
			if ( $element->parentNode ) {
				$element->parentNode->removeChild( $element );
			}
		}
		return $list;
	}

	/**
	 * libxml in its usual pointlessness converts many chars to entities - this function
	 * perfoms a reverse conversion
	 * @param string $html
	 * @return string
	 */
	private function fixLibXML( $html ) {
		// We don't include rules like '&#34;' => '&amp;quot;' because entities had already been
		// normalized by libxml. Using this function with input not sanitized by libxml is UNSAFE!
		$replacements = [
			'&quot;' => '&amp;quot;',
			'&amp;' => '&amp;amp;',
			'&lt;' => '&amp;lt;',
			'&gt;' => '&amp;gt;',
		];
		$html = strtr( $html, $replacements );

		// Just in case the conversion in getDoc() above used named
		// entities that aren't known to html_entity_decode().
		$html = \mb_convert_encoding( $html, 'UTF-8', 'HTML-ENTITIES' );
		return $html;
	}

	/**
	 * Performs final transformations and returns resulting HTML.  Note that if you want to call this
	 * both without an element and with an element you should call it without an element first.  If you
	 * specify the $element in the method it'll change the underlying dom and you won't be able to get
	 * it back.
	 *
	 * @param \DOMElement|string|null $element ID of element to get HTML from or
	 *   false to get it from the whole tree
	 * @return string Processed HTML
	 */
	public function getText( $element = null ) {
		if ( $this->doc ) {
			if ( $element !== null && !( $element instanceof \DOMElement ) ) {
				$element = $this->doc->getElementById( $element );
			}
			if ( $element ) {
				$body = $this->doc->getElementsByTagName( 'body' )->item( 0 );
				$nodesArray = [];
				foreach ( $body->childNodes as $node ) {
					$nodesArray[] = $node;
				}
				foreach ( $nodesArray as $nodeArray ) {
					$body->removeChild( $nodeArray );
				}
				$body->appendChild( $element );
			}
			$html = $this->doc->saveHTML();

			$html = $this->fixLibXml( $html );
			if ( PHP_EOL === "\r\n" ) {
				// Cleanup for CRLF misprocessing of unknown origin on Windows.
				$html = str_replace( '&#13;', '', $html );
			}
		} else {
			$html = $this->html;
		}
		// Remove stuff added by wrapHTML()
		$html = \preg_replace( '/<!--.*?-->|^.*?<body>|<\/body>.*$/s', '', $html );
		$html = $this->onHtmlReady( $html );

		if ( $this->elementsToFlatten ) {
			$elements = \implode( '|', $this->elementsToFlatten );
			$html = \preg_replace( "#</?(?:$elements)\\b[^>]*>#is", '', $html );
		}

		return $html;
	}

	/**
	 * Helper function for parseItemsToRemove(). This function extracts the selector type
	 * and the raw name of a selector from a CSS-style selector string and assigns those
	 * values to parameters passed by reference. For example, if given '#toc' as the
	 * $selector parameter, it will assign 'ID' as the $type and 'toc' as the $rawName.
	 * @param string $selector CSS selector to parse
	 * @param string &$type The type of selector (ID, CLASS, TAG_CLASS, or TAG)
	 * @param string &$rawName The raw name of the selector
	 * @return bool Whether the selector was successfully recognised
	 * @throws \Exception
	 */
	protected function parseSelector( $selector, &$type, &$rawName ) {
		$firstChar = substr( $selector, 0, 1 );
		if ( $firstChar === '.' ) {
			$type = 'CLASS';
			$rawName = substr( $selector, 1 );
		} elseif ( $firstChar === '#' ) {
			$type = 'ID';
			$rawName = substr( $selector, 1 );
		} elseif ( strpos( $selector, '.' ) > 0 ) {
			$type = 'TAG_CLASS';
			$rawName = $selector;
		} elseif ( strpos( $selector, '[' ) === false && strpos( $selector, ']' ) === false ) {
			$type = 'TAG';
			$rawName = $selector;
		} else {
			throw new \Exception( __METHOD__ . "(): unrecognized selector '$selector'" );
		}

		return true;
	}

	/**
	 * Transforms CSS-style selectors into an internal representation suitable for
	 * processing by filterContent()
	 * @return array
	 */
	protected function parseItemsToRemove() {
		$removals = [
			'ID' => [],
			'TAG' => [],
			'CLASS' => [],
			'TAG_CLASS' => [],
		];

		foreach ( $this->itemsToRemove as $itemToRemove ) {
			$type = '';
			$rawName = '';
			if ( $this->parseSelector( $itemToRemove, $type, $rawName ) ) {
				$removals[$type][] = $rawName;
			}
		}

		if ( $this->removeMedia ) {
			$removals['TAG'][] = 'img';
			$removals['TAG'][] = 'audio';
			$removals['TAG'][] = 'video';
		}

		return $removals;
	}
}
