<?php
/**
 * Contains classes for formatting log entries
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
 * @author Niklas Laxström
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @since 1.19
 */

/**
 * Implements the default log formatting.
 * Can be overridden by subclassing and setting
 * $wgLogActionsHandlers['type/subtype'] = 'class'; or
 * $wgLogActionsHandlers['type/*'] = 'class';
 * @since 1.19
 */
class LogFormatter {
	// Audience options for viewing usernames, comments, and actions
	const FOR_PUBLIC = 1;
	const FOR_THIS_USER = 2;

	// Static->

	/**
	 * Constructs a new formatter suitable for given entry.
	 * @param $entry LogEntry
	 * @return LogFormatter
	 */
	public static function newFromEntry( LogEntry $entry ) {
		global $wgLogActionsHandlers;
		$fulltype = $entry->getFullType();
		$wildcard = $entry->getType() . '/*';
		$handler = '';

		if ( isset( $wgLogActionsHandlers[$fulltype] ) ) {
			$handler = $wgLogActionsHandlers[$fulltype];
		} elseif ( isset( $wgLogActionsHandlers[$wildcard] ) ) {
			$handler = $wgLogActionsHandlers[$wildcard];
		}

		if ( $handler !== '' && is_string( $handler ) && class_exists( $handler ) ) {
			return new $handler( $entry );
		}

		return new LegacyLogFormatter( $entry );
	}

	/**
	 * Handy shortcut for constructing a formatter directly from
	 * database row.
	 * @param $row
	 * @see DatabaseLogEntry::getSelectQueryData
	 * @return LogFormatter
	 */
	public static function newFromRow( $row ) {
		return self::newFromEntry( DatabaseLogEntry::newFromRow( $row ) );
	}

	// Nonstatic->

	/// @var LogEntry
	protected $entry;

	/// Integer constant for handling log_deleted
	protected $audience = self::FOR_PUBLIC;

	/// Whether to output user tool links
	protected $linkFlood = false;

	/**
	 * Set to true if we are constructing a message text that is going to
	 * be included in page history or send to IRC feed. Links are replaced
	 * with plaintext or with [[pagename]] kind of syntax, that is parsed
	 * by page histories and IRC feeds.
	 * @var boolean
	 */
	protected $plaintext = false;

	protected $irctext = false;

	protected function __construct( LogEntry $entry ) {
		$this->entry = $entry;
		$this->context = RequestContext::getMain();
	}

	/**
	 * Replace the default context
	 * @param $context IContextSource
	 */
	public function setContext( IContextSource $context ) {
		$this->context = $context;
	}

	/**
	 * Set the visibility restrictions for displaying content.
	 * If set to public, and an item is deleted, then it will be replaced
	 * with a placeholder even if the context user is allowed to view it.
	 * @param $audience integer self::FOR_THIS_USER or self::FOR_PUBLIC
	 */
	public function setAudience( $audience ) {
		$this->audience = ( $audience == self::FOR_THIS_USER )
			? self::FOR_THIS_USER
			: self::FOR_PUBLIC;
	}

	/**
	 * Check if a log item can be displayed
	 * @param $field integer LogPage::DELETED_* constant
	 * @return bool
	 */
	protected function canView( $field ) {
		if ( $this->audience == self::FOR_THIS_USER ) {
			return LogEventsList::userCanBitfield(
				$this->entry->getDeleted(), $field, $this->context->getUser() );
		} else {
			return !$this->entry->isDeleted( $field );
		}
	}

	/**
	 * If set to true, will produce user tool links after
	 * the user name. This should be replaced with generic
	 * CSS/JS solution.
	 * @param $value boolean
	 */
	public function setShowUserToolLinks( $value ) {
		$this->linkFlood = $value;
	}

	/**
	 * Ugly hack to produce plaintext version of the message.
	 * Usually you also want to set extraneous request context
	 * to avoid formatting for any particular user.
	 * @see getActionText()
	 * @return string text
	 */
	public function getPlainActionText() {
		$this->plaintext = true;
		$text = $this->getActionText();
		$this->plaintext = false;
		return $text;
	}

	/**
	 * Even uglier hack to maintain backwards compatibilty with IRC bots
	 * (bug 34508).
	 * @see getActionText()
	 * @return string text
	 */
	public function getIRCActionComment() {
		$actionComment = $this->getIRCActionText();
		$comment = $this->entry->getComment();

		if ( $comment != '' ) {
			if ( $actionComment == '' ) {
				$actionComment = $comment;
			} else {
				$actionComment .= wfMessage( 'colon-separator' )->inContentLanguage()->text() . $comment;
			}
		}

		return $actionComment;
	}

	/**
	 * Even uglier hack to maintain backwards compatibilty with IRC bots
	 * (bug 34508).
	 * @see getActionText()
	 * @return string text
	 */
	public function getIRCActionText() {
		$this->plaintext = true;
		$this->irctext = true;

		$entry = $this->entry;
		$parameters = $entry->getParameters();
		// @see LogPage::actionText()
		// Text of title the action is aimed at.
		$target = $entry->getTarget()->getPrefixedText();
		$text = null;
		switch( $entry->getType() ) {
			case 'move':
				switch( $entry->getSubtype() ) {
					case 'move':
						$movesource = $parameters['4::target'];
						$text = wfMessage( '1movedto2' )
							->rawParams( $target, $movesource )->inContentLanguage()->escaped();
						break;
					case 'move_redir':
						$movesource = $parameters['4::target'];
						$text = wfMessage( '1movedto2_redir' )
							->rawParams( $target, $movesource )->inContentLanguage()->escaped();
						break;
					case 'move-noredirect':
						break;
					case 'move_redir-noredirect':
						break;
				}
				break;

			case 'delete':
				switch( $entry->getSubtype() ) {
					case 'delete':
						$text = wfMessage( 'deletedarticle' )
							->rawParams( $target )->inContentLanguage()->escaped();
						break;
					case 'restore':
						$text = wfMessage( 'undeletedarticle' )
							->rawParams( $target )->inContentLanguage()->escaped();
						break;
					//case 'revision': // Revision deletion
					//case 'event': // Log deletion
						// see https://svn.wikimedia.org/viewvc/mediawiki/trunk/phase3/includes/LogPage.php?&pathrev=97044&r1=97043&r2=97044
					//default:
				}
				break;

			case 'patrol':
				// https://svn.wikimedia.org/viewvc/mediawiki/trunk/phase3/includes/PatrolLog.php?&pathrev=97495&r1=97494&r2=97495
				// Create a diff link to the patrolled revision
				if ( $entry->getSubtype() === 'patrol' ) {
					$diffLink = htmlspecialchars(
						wfMessage( 'patrol-log-diff', $parameters['4::curid'] )
							->inContentLanguage()->text() );
					$text = wfMessage( 'patrol-log-line', $diffLink, "[[$target]]", "" )
						->inContentLanguage()->text();
				} else {
					// broken??
				}
				break;

			case 'protect':
				switch( $entry->getSubtype() ) {
				case 'protect':
					$text = wfMessage( 'protectedarticle' )
						->rawParams( $target . ' ' . $parameters[0] )->inContentLanguage()->escaped();
					break;
				case 'unprotect':
					$text = wfMessage( 'unprotectedarticle' )
						->rawParams( $target )->inContentLanguage()->escaped();
					break;
				case 'modify':
					$text = wfMessage( 'modifiedarticleprotection' )
						->rawParams( $target . ' ' . $parameters[0] )->inContentLanguage()->escaped();
					break;
				}
				break;

			case 'newusers':
				switch( $entry->getSubtype() ) {
					case 'newusers':
					case 'create':
						$text = wfMessage( 'newuserlog-create-entry' )
							->inContentLanguage()->escaped();
						break;
					case 'create2':
					case 'byemail':
						$text = wfMessage( 'newuserlog-create2-entry' )
							->rawParams( $target )->inContentLanguage()->escaped();
						break;
					case 'autocreate':
						$text = wfMessage( 'newuserlog-autocreate-entry' )
							->inContentLanguage()->escaped();
						break;
				}
				break;

			case 'upload':
				switch( $entry->getSubtype() ) {
					case 'upload':
						$text = wfMessage( 'uploadedimage' )
							->rawParams( $target )->inContentLanguage()->escaped();
						break;
					case 'overwrite':
						$text = wfMessage( 'overwroteimage' )
							->rawParams( $target )->inContentLanguage()->escaped();
						break;
				}
				break;

			case 'rights':
				if ( count( $parameters['4::oldgroups'] ) ) {
					$oldgroups = implode( ', ', $parameters['4::oldgroups'] );
				} else {
					$oldgroups = wfMessage( 'rightsnone' )->inContentLanguage()->escaped();
				}
				if ( count( $parameters['5::newgroups'] ) ) {
					$newgroups = implode( ', ', $parameters['5::newgroups'] );
				} else {
					$newgroups = wfMessage( 'rightsnone' )->inContentLanguage()->escaped();
				}
				switch( $entry->getSubtype() ) {
					case 'rights':
						$text = wfMessage( 'rightslogentry' )
							->rawParams( $target, $oldgroups, $newgroups )->inContentLanguage()->escaped();
						break;
					case 'autopromote':
						$text = wfMessage( 'rightslogentry-autopromote' )
							->rawParams( $target, $oldgroups, $newgroups )->inContentLanguage()->escaped();
						break;
				}
				break;

			// case 'suppress' --private log -- aaron  (sign your messages so we know who to blame in a few years :-D)
			// default:
		}
		if( is_null( $text ) ) {
			$text = $this->getPlainActionText();
		}

		$this->plaintext = false;
		$this->irctext = false;
		return $text;
	}

	/**
	 * Gets the log action, including username.
	 * @return string HTML
	 */
	public function getActionText() {
		if ( $this->canView( LogPage::DELETED_ACTION ) ) {
			$element = $this->getActionMessage();
			if ( $element instanceof Message ) {
				$element = $this->plaintext ? $element->text() : $element->escaped();
			}
			if ( $this->entry->isDeleted( LogPage::DELETED_ACTION ) ) {
				$element = $this->styleRestricedElement( $element );
			}
		} else {
			$performer = $this->getPerformerElement() . $this->msg( 'word-separator' )->text();
			$element = $performer . $this->getRestrictedElement( 'rev-deleted-event' );
		}

		return $element;
	}

	/**
	 * Returns a sentence describing the log action. Usually
	 * a Message object is returned, but old style log types
	 * and entries might return pre-escaped html string.
	 * @return Message|string pre-escaped html
	 */
	protected function getActionMessage() {
		$message = $this->msg( $this->getMessageKey() );
		$message->params( $this->getMessageParameters() );
		return $message;
	}

	/**
	 * Returns a key to be used for formatting the action sentence.
	 * Default is logentry-TYPE-SUBTYPE for modern logs. Legacy log
	 * types will use custom keys, and subclasses can also alter the
	 * key depending on the entry itself.
	 * @return string message key
	 */
	protected function getMessageKey() {
		$type = $this->entry->getType();
		$subtype = $this->entry->getSubtype();

		return "logentry-$type-$subtype";
	}

	/**
	 * Returns extra links that comes after the action text, like "revert", etc.
	 *
	 * @return string
	 */
	public function getActionLinks() {
		return '';
	}

	/**
	 * Extracts the optional extra parameters for use in action messages.
	 * The array indexes start from number 3.
	 * @return array
	 */
	protected function extractParameters() {
		$entry = $this->entry;
		$params = array();

		if ( $entry->isLegacy() ) {
			foreach ( $entry->getParameters() as $index => $value ) {
				$params[$index + 3] = $value;
			}
		}

		// Filter out parameters which are not in format #:foo
		foreach ( $entry->getParameters() as $key => $value ) {
			if ( strpos( $key, ':' ) === false ) {
				continue;
			}
			list( $index, $type, ) = explode( ':', $key, 3 );
			$params[$index - 1] = $this->formatParameterValue( $type, $value );
		}

		/* Message class doesn't like non consecutive numbering.
		 * Fill in missing indexes with empty strings to avoid
		 * incorrect renumbering.
		 */
		if ( count( $params ) ) {
			$max = max( array_keys( $params ) );
			for ( $i = 4; $i < $max; $i++ ) {
				if ( !isset( $params[$i] ) ) {
					$params[$i] = '';
				}
			}
		}
		return $params;
	}

	/**
	 * Formats parameters intented for action message from
	 * array of all parameters. There are three hardcoded
	 * parameters (array is zero-indexed, this list not):
	 *  - 1: user name with premade link
	 *  - 2: usable for gender magic function
	 *  - 3: target page with premade link
	 * @return array
	 */
	protected function getMessageParameters() {
		if ( isset( $this->parsedParameters ) ) {
			return $this->parsedParameters;
		}

		$entry = $this->entry;
		$params = $this->extractParameters();
		$params[0] = Message::rawParam( $this->getPerformerElement() );
		$params[1] = $this->canView( LogPage::DELETED_USER ) ? $entry->getPerformer()->getRealName() : '';
		$params[2] = Message::rawParam( $this->makePageLink( $entry->getTarget() ) );

		// Bad things happens if the numbers are not in correct order
		ksort( $params );
		return $this->parsedParameters = $params;
	}

	/**
	 * Formats parameters values dependent to their type
	 * @param string $type The type of the value.
	 *   Valid are currently:
	 *     * - (empty) or plain: The value is returned as-is
	 *     * raw: The value will be added to the log message
	 *            as raw parameter (e.g. no escaping)
	 *            Use this only if there is no other working
	 *            type like user-link or title-link
	 *     * msg: The value is a message-key, the output is
	 *            the message in user language
	 *     * msg-content: The value is a message-key, the output
	 *                    is the message in content language
	 *     * user: The value is a user name, e.g. for GENDER
	 *     * user-link: The value is a user name, returns a
	 *                  link for the user
	 *     * title: The value is a page title,
	 *              returns name of page
	 *     * title-link: The value is a page title,
	 *                   returns link to this page
	 *     * number: Format value as number
	 * @param string $value The parameter value that should
	 *                      be formated
	 * @return string or Message::numParam or Message::rawParam
	 *         Formated value
	 * @since 1.21
	 */
	protected function formatParameterValue( $type, $value ) {
		$saveLinkFlood = $this->linkFlood;

		switch( strtolower( trim( $type ) ) ) {
			case 'raw':
				$value = Message::rawParam( $value );
				break;
			case 'msg':
				$value = $this->msg( $value )->text();
				break;
			case 'msg-content':
				$value = $this->msg( $value )->inContentLanguage()->text();
				break;
			case 'number':
				$value = Message::numParam( $value );
				break;
			case 'user':
				$user = User::newFromName( $value );
				$value = $user->getRealName();
				break;
			case 'user-link':
				$this->setShowUserToolLinks( false );

				$user = User::newFromName( $value );
				$value = Message::rawParam( $this->makeUserLink( $user ) );

				$this->setShowUserToolLinks( $saveLinkFlood );
				break;
			case 'title':
				$title = Title::newFromText( $value );
				$value = $title->getPrefixedText();
				break;
			case 'title-link':
				$title = Title::newFromText( $value );
				$value = Message::rawParam( $this->makePageLink( $title ) );
				break;
			case 'plain':
				// Plain text, nothing to do
			default:
				// Catch other types and use the old behavior (return as-is)
		}

		return $value;
	}

	/**
	 * Helper to make a link to the page, taking the plaintext
	 * value in consideration.
	 * @param $title Title the page
	 * @param array $parameters query parameters
	 * @throws MWException
	 * @return String
	 */
	protected function makePageLink( Title $title = null, $parameters = array() ) {
		if ( !$this->plaintext ) {
			$link = Linker::link( $title, null, array(), $parameters );
		} else {
			if ( !$title instanceof Title ) {
				throw new MWException( "Expected title, got null" );
			}
			$link = '[[' . $title->getPrefixedText() . ']]';
		}
		return $link;
	}

	/**
	 * Provides the name of the user who performed the log action.
	 * Used as part of log action message or standalone, depending
	 * which parts of the log entry has been hidden.
	 * @return String
	 */
	public function getPerformerElement() {
		if ( $this->canView( LogPage::DELETED_USER ) ) {
			$performer = $this->entry->getPerformer();
			$element = $this->makeUserLink( $performer );
			if ( $this->entry->isDeleted( LogPage::DELETED_USER ) ) {
				$element = $this->styleRestricedElement( $element );
			}
		} else {
			$element = $this->getRestrictedElement( 'rev-deleted-user' );
		}

		return $element;
	}

	/**
	 * Gets the luser provided comment
	 * @return string HTML
	 */
	public function getComment() {
		if ( $this->canView( LogPage::DELETED_COMMENT ) ) {
			$comment = Linker::commentBlock( $this->entry->getComment() );
			// No hard coded spaces thanx
			$element = ltrim( $comment );
			if ( $this->entry->isDeleted( LogPage::DELETED_COMMENT ) ) {
				$element = $this->styleRestricedElement( $element );
			}
		} else {
			$element = $this->getRestrictedElement( 'rev-deleted-comment' );
		}

		return $element;
	}

	/**
	 * Helper method for displaying restricted element.
	 * @param $message string
	 * @return string HTML or wikitext
	 */
	protected function getRestrictedElement( $message ) {
		if ( $this->plaintext ) {
			return $this->msg( $message )->text();
		}

		$content = $this->msg( $message )->escaped();
		$attribs = array( 'class' => 'history-deleted' );
		return Html::rawElement( 'span', $attribs, $content );
	}

	/**
	 * Helper method for styling restricted element.
	 * @param $content string
	 * @return string HTML or wikitext
	 */
	protected function styleRestricedElement( $content ) {
		if ( $this->plaintext ) {
			return $content;
		}
		$attribs = array( 'class' => 'history-deleted' );
		return Html::rawElement( 'span', $attribs, $content );
	}

	/**
	 * Shortcut for wfMessage which honors local context.
	 * @todo Would it be better to require replacing the global context instead?
	 * @param $key string
	 * @return Message
	 */
	protected function msg( $key ) {
		return $this->context->msg( $key );
	}

	protected function makeUserLink( User $user ) {
		if ( $this->plaintext ) {
			$element = $user->getRealName();
		} else {
			$element = Linker::userLink(
				$user->getId(),
				$user->mRealName
			);

			if ( $this->linkFlood ) {
				$element .= Linker::userToolLinksRedContribs(
					$user->getId(),
					$user->mRealName,
					$user->getEditCount()
				);
			}
		}
		return $element;
	}

	/**
	 * @return Array of titles that should be preloaded with LinkBatch.
	 */
	public function getPreloadTitles() {
		return array();
	}

	/**
	 * @return Output of getMessageParameters() for testing
	 */
	public function getMessageParametersForTesting() {
		// This function was added because getMessageParameters() is
		// protected and a change from protected to public caused
		// problems with extensions
		return $this->getMessageParameters();
	}

}

/**
 * This class formats all log entries for log types
 * which have not been converted to the new system.
 * This is not about old log entries which store
 * parameters in a different format - the new
 * LogFormatter classes have code to support formatting
 * those too.
 * @since 1.19
 */
class LegacyLogFormatter extends LogFormatter {

	/**
	 * Backward compatibility for extension changing the comment from
	 * the LogLine hook. This will be set by the first call on getComment(),
	 * then it might be modified by the hook when calling getActionLinks(),
	 * so that the modified value will be returned when calling getComment()
	 * a second time.
	 *
	 * @var string|null
	 */
	private $comment = null;

	/**
	 * Cache for the result of getActionLinks() so that it does not need to
	 * run multiple times depending on the order that getComment() and
	 * getActionLinks() are called.
	 *
	 * @var string|null
	 */
	private $revert = null;

	public function getComment() {
		if ( $this->comment === null ) {
			$this->comment = parent::getComment();
		}

		// Make sure we execute the LogLine hook so that we immediately return
		// the correct value.
		if ( $this->revert === null ) {
			$this->getActionLinks();
		}

		return $this->comment;
	}

	protected function getActionMessage() {
		$entry = $this->entry;
		$action = LogPage::actionText(
			$entry->getType(),
			$entry->getSubtype(),
			$entry->getTarget(),
			$this->plaintext ? null : $this->context->getSkin(),
			(array)$entry->getParameters(),
			!$this->plaintext // whether to filter [[]] links
		);

		$performer = $this->getPerformerElement();
		if ( !$this->irctext ) {
			$action = $performer . $this->msg( 'word-separator' )->text() . $action;
		}

		return $action;
	}

	public function getActionLinks() {
		if ( $this->revert !== null ) {
			return $this->revert;
		}

		if ( $this->entry->isDeleted( LogPage::DELETED_ACTION ) ) {
			return $this->revert = '';
		}

		$title = $this->entry->getTarget();
		$type = $this->entry->getType();
		$subtype = $this->entry->getSubtype();

		// Show unblock/change block link
		if ( ( $type == 'block' || $type == 'suppress' ) && ( $subtype == 'block' || $subtype == 'reblock' ) ) {
			if ( !$this->context->getUser()->isAllowed( 'block' ) ) {
				return '';
			}

			$links = array(
				Linker::linkKnown(
					SpecialPage::getTitleFor( 'Unblock', $title->getDBkey() ),
					$this->msg( 'unblocklink' )->escaped()
				),
				Linker::linkKnown(
					SpecialPage::getTitleFor( 'Block', $title->getDBkey() ),
					$this->msg( 'change-blocklink' )->escaped()
				)
			);
			return $this->msg( 'parentheses' )->rawParams(
				$this->context->getLanguage()->pipeList( $links ) )->escaped();
		// Show change protection link
		} elseif ( $type == 'protect' && ( $subtype == 'protect' || $subtype == 'modify' || $subtype == 'unprotect' ) ) {
			$links = array(
				Linker::link( $title,
					$this->msg( 'hist' )->escaped(),
					array(),
					array(
						'action' => 'history',
						'offset' => $this->entry->getTimestamp()
					)
				)
			);
			if ( $this->context->getUser()->isAllowed( 'protect' ) ) {
				$links[] = Linker::linkKnown(
					$title,
					$this->msg( 'protect_change' )->escaped(),
					array(),
					array( 'action' => 'protect' )
				);
			}
			return $this->msg( 'parentheses' )->rawParams(
				$this->context->getLanguage()->pipeList( $links ) )->escaped();
		// Show unmerge link
		} elseif( $type == 'merge' && $subtype == 'merge' ) {
			if ( !$this->context->getUser()->isAllowed( 'mergehistory' ) ) {
				return '';
			}

			$params = $this->extractParameters();
			$revert = Linker::linkKnown(
				SpecialPage::getTitleFor( 'MergeHistory' ),
				$this->msg( 'revertmerge' )->escaped(),
				array(),
				array(
					'target' => $params[3],
					'dest' => $title->getPrefixedDBkey(),
					'mergepoint' => $params[4]
				)
			);
			return $this->msg( 'parentheses' )->rawParams( $revert )->escaped();
		}

		// Do nothing. The implementation is handled by the hook modifiying the
		// passed-by-ref parameters. This also changes the default value so that
		// getComment() and getActionLinks() do not call them indefinitely.
		$this->revert = '';

		// This is to populate the $comment member of this instance so that it
		// can be modified when calling the hook just below.
		if ( $this->comment === null ) {
			$this->getComment();
		}

		$params = $this->entry->getParameters();

		wfRunHooks( 'LogLine', array( $type, $subtype, $title, $params,
			&$this->comment, &$this->revert, $this->entry->getTimestamp() ) );

		return $this->revert;
	}
}

/**
 * This class formats move log entries.
 * @since 1.19
 */
class MoveLogFormatter extends LogFormatter {
	public function getPreloadTitles() {
		$params = $this->extractParameters();
		return array( Title::newFromText( $params[3] ) );
	}

	protected function getMessageKey() {
		$key = parent::getMessageKey();
		$params = $this->getMessageParameters();
		if ( isset( $params[4] ) && $params[4] === '1' ) {
			$key .= '-noredirect';
		}
		return $key;
	}

	protected function getMessageParameters() {
		$params = parent::getMessageParameters();
		$oldname = $this->makePageLink( $this->entry->getTarget(), array( 'redirect' => 'no' ) );
		$newname = $this->makePageLink( Title::newFromText( $params[3] ) );
		$params[2] = Message::rawParam( $oldname );
		$params[3] = Message::rawParam( $newname );
		return $params;
	}

	public function getActionLinks() {
		if ( $this->entry->isDeleted( LogPage::DELETED_ACTION ) // Action is hidden
			|| $this->entry->getSubtype() !== 'move'
			|| !$this->context->getUser()->isAllowed( 'move' ) )
		{
			return '';
		}

		$params = $this->extractParameters();
		$destTitle = Title::newFromText( $params[3] );
		if ( !$destTitle ) {
			return '';
		}

		$revert = Linker::linkKnown(
			SpecialPage::getTitleFor( 'Movepage' ),
			$this->msg( 'revertmove' )->escaped(),
			array(),
			array(
				'wpOldTitle' => $destTitle->getPrefixedDBkey(),
				'wpNewTitle' => $this->entry->getTarget()->getPrefixedDBkey(),
				'wpReason'   => $this->msg( 'revertmove' )->inContentLanguage()->text(),
				'wpMovetalk' => 0
			)
		);
		return $this->msg( 'parentheses' )->rawParams( $revert )->escaped();
	}
}

/**
 * This class formats delete log entries.
 * @since 1.19
 */
class DeleteLogFormatter extends LogFormatter {
	protected function getMessageKey() {
		$key = parent::getMessageKey();
		if ( in_array( $this->entry->getSubtype(), array( 'event', 'revision' ) ) ) {
			if ( count( $this->getMessageParameters() ) < 5 ) {
				return "$key-legacy";
			}
		}
		return $key;
	}

	protected function getMessageParameters() {
		if ( isset( $this->parsedParametersDeleteLog ) ) {
			return $this->parsedParametersDeleteLog;
		}

		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();
		if ( in_array( $subtype, array( 'event', 'revision' ) ) ) {
			// $params[3] here is 'revision' for page revisions, 'oldimage' for file versions, or a comma-separated list of log_ids for log entries.
			// $subtype here is 'revision' for page revisions and file versions, or 'event' for log entries.
			if (
				( $subtype === 'event' && count( $params ) === 6 ) ||
				( $subtype === 'revision' && isset( $params[3] ) && ( $params[3] === 'revision' || $params[3] === 'oldimage' ) )
			) {
				$paramStart = $subtype === 'revision' ? 4 : 3;

				$old = $this->parseBitField( $params[$paramStart+1] );
				$new = $this->parseBitField( $params[$paramStart+2] );
				list( $hid, $unhid, $extra ) = RevisionDeleter::getChanges( $new, $old );
				$changes = array();
				foreach ( $hid as $v ) {
					$changes[] = $this->msg( "$v-hid" )->plain();
				}
				foreach ( $unhid as $v ) {
					$changes[] = $this->msg( "$v-unhid" )->plain();
				}
				foreach ( $extra as $v ) {
					$changes[] = $this->msg( $v )->plain();
				}
				$changeText = $this->context->getLanguage()->listToText( $changes );

				$newParams = array_slice( $params, 0, 3 );
				$newParams[3] = $changeText;
				$count = count( explode( ',', $params[$paramStart] ) );
				$newParams[4] = $this->context->getLanguage()->formatNum( $count );
				return $this->parsedParametersDeleteLog = $newParams;
			} else {
				return $this->parsedParametersDeleteLog = array_slice( $params, 0, 3 );
			}
		}

		return $this->parsedParametersDeleteLog = $params;
	}

	protected function parseBitField( $string ) {
		// Input is like ofield=2134 or just the number
		if ( strpos( $string, 'field=' ) === 1 ) {
			list( , $field ) = explode( '=', $string );
			return (int) $field;
		} else {
			return (int) $string;
		}
	}

	public function getActionLinks() {
		$user = $this->context->getUser();
		if ( !$user->isAllowed( 'deletedhistory' ) || $this->entry->isDeleted( LogPage::DELETED_ACTION ) ) {
			return '';
		}

		switch ( $this->entry->getSubtype() ) {
		case 'delete': // Show undelete link
			if( $user->isAllowed( 'undelete' ) ) {
				$message = 'undeletelink';
			} else {
				$message = 'undeleteviewlink';
			}
			$revert = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Undelete' ),
				$this->msg( $message )->escaped(),
				array(),
				array( 'target' => $this->entry->getTarget()->getPrefixedDBkey() )
			);
			return $this->msg( 'parentheses' )->rawParams( $revert )->escaped();

		case 'revision': // If an edit was hidden from a page give a review link to the history
			$params = $this->extractParameters();
			if ( !isset( $params[3] ) || !isset( $params[4] ) ) {
				return '';
			}

			// Different revision types use different URL params...
			$key = $params[3];
			// This is a CSV of the IDs
			$ids = explode( ',', $params[4] );

			$links = array();

			// If there's only one item, we can show a diff link
			if ( count( $ids ) == 1 ) {
				// Live revision diffs...
				if ( $key == 'oldid' || $key == 'revision' ) {
					$links[] = Linker::linkKnown(
						$this->entry->getTarget(),
						$this->msg( 'diff' )->escaped(),
						array(),
						array(
							'diff' => intval( $ids[0] ),
							'unhide' => 1
						)
					);
				// Deleted revision diffs...
				} elseif ( $key == 'artimestamp' || $key == 'archive' ) {
					$links[] = Linker::linkKnown(
						SpecialPage::getTitleFor( 'Undelete' ),
						$this->msg( 'diff' )->escaped(),
						array(),
						array(
							'target'    => $this->entry->getTarget()->getPrefixedDBkey(),
							'diff'      => 'prev',
							'timestamp' => $ids[0]
						)
					);
				}
			}

			// View/modify link...
			$links[] = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Revisiondelete' ),
				$this->msg( 'revdel-restore' )->escaped(),
				array(),
				array(
					'target' => $this->entry->getTarget()->getPrefixedText(),
					'type' => $key,
					'ids' => implode( ',', $ids ),
				)
			);

			return $this->msg( 'parentheses' )->rawParams(
				$this->context->getLanguage()->pipeList( $links ) )->escaped();

		case 'event': // Hidden log items, give review link
			$params = $this->extractParameters();
			if ( !isset( $params[3] ) ) {
				return '';
			}
			// This is a CSV of the IDs
			$query = $params[3];
			// Link to each hidden object ID, $params[1] is the url param
			$revert = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Revisiondelete' ),
				$this->msg( 'revdel-restore' )->escaped(),
				array(),
				array(
					'target' => $this->entry->getTarget()->getPrefixedText(),
					'type' => 'logging',
					'ids' => $query
				)
			);
			return $this->msg( 'parentheses' )->rawParams( $revert )->escaped();
		default:
			return '';
		}
	}
}

/**
 * This class formats patrol log entries.
 * @since 1.19
 */
class PatrolLogFormatter extends LogFormatter {
	protected function getMessageKey() {
		$key = parent::getMessageKey();
		$params = $this->getMessageParameters();
		if ( isset( $params[5] ) && $params[5] ) {
			$key .= '-auto';
		}
		return $key;
	}

	protected function getMessageParameters() {
		$params = parent::getMessageParameters();

		$target = $this->entry->getTarget();
		$oldid = $params[3];
		$revision = $this->context->getLanguage()->formatNum( $oldid, true );

		if ( $this->plaintext ) {
			$revlink = $revision;
		} elseif ( $target->exists() ) {
			$query = array(
				'oldid' => $oldid,
				'diff' => 'prev'
			);
			$revlink = Linker::link( $target, htmlspecialchars( $revision ), array(), $query );
		} else {
			$revlink = htmlspecialchars( $revision );
		}

		$params[3] = Message::rawParam( $revlink );
		return $params;
	}
}

/**
 * This class formats new user log entries.
 * @since 1.19
 */
class NewUsersLogFormatter extends LogFormatter {
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();
		if ( $subtype === 'create2' || $subtype === 'byemail' ) {
			if ( isset( $params[3] ) ) {
				$target = User::newFromId( $params[3] );
			} else {
				$target = User::newFromName( $this->entry->getTarget()->getText(), false );
			}
			$params[2] = Message::rawParam( $this->makeUserLink( $target ) );
			$params[3] = $target->getRealName();
		}
		return $params;
	}

	public function getComment() {
		$timestamp = wfTimestamp( TS_MW, $this->entry->getTimestamp() );
		if ( $timestamp < '20080129000000' ) {
			# Suppress $comment from old entries (before 2008-01-29),
			# not needed and can contain incorrect links
			return '';
		}
		return parent::getComment();
	}

	public function getPreloadTitles() {
		$subtype = $this->entry->getSubtype();
		if ( $subtype === 'create2' || $subtype === 'byemail' ) {
			//add the user talk to LinkBatch for the userLink
			return array( Title::makeTitle( NS_USER_TALK, $this->entry->getTarget()->getText() ) );
		}
		return array();
	}
}

/**
 * This class formats rights log entries.
 * @since 1.21
 */
class RightsLogFormatter extends LogFormatter {
	protected function makePageLink( Title $title = null, $parameters = array() ) {
		global $wgContLang, $wgUserrightsInterwikiDelimiter;

		if ( !$this->plaintext ) {
			$text = $wgContLang->ucfirst( $title->getText() );
			$parts = explode( $wgUserrightsInterwikiDelimiter, $text, 2 );

			if ( count( $parts ) === 2 ) {
				$titleLink = WikiMap::foreignUserLink( $parts[1], $parts[0],
					htmlspecialchars( $title->getPrefixedText() ) );

				if ( $titleLink !== false ) {
					return $titleLink;
				}
			}
		}

		return parent::makePageLink( $title, $parameters );
	}

	protected function getMessageKey() {
		$key = parent::getMessageKey();
		$params = $this->getMessageParameters();
		if ( !isset( $params[3] ) && !isset( $params[4] ) ) {
			$key .= '-legacy';
		}
		return $key;
	}

	protected function getMessageParameters() {
		$params = parent::getMessageParameters();

		// Really old entries
		if ( !isset( $params[3] ) && !isset( $params[4] ) ) {
			return $params;
		}

		$oldGroups = $params[3];
		$newGroups = $params[4];

		// Less old entries
		if ( $oldGroups === '' ) {
			$oldGroups = array();
		} elseif ( is_string( $oldGroups ) ) {
			$oldGroups = array_map( 'trim', explode( ',', $oldGroups ) );
		}
		if ( $newGroups === '' ) {
			$newGroups = array();
		} elseif ( is_string( $newGroups ) ) {
			$newGroups = array_map( 'trim', explode( ',', $newGroups ) );
		}

		$userName = $this->entry->getTarget()->getText();
		if ( !$this->plaintext && count( $oldGroups ) ) {
			foreach ( $oldGroups as &$group ) {
				$group = User::getGroupMember( $group, $userName );
			}
		}
		if ( !$this->plaintext && count( $newGroups ) ) {
			foreach ( $newGroups as &$group ) {
				$group = User::getGroupMember( $group, $userName );
			}
		}

		$lang = $this->context->getLanguage();
		if ( count( $oldGroups ) ) {
			$params[3] = $lang->listToText( $oldGroups );
		} else {
			$params[3] = $this->msg( 'rightsnone' )->text();
		}
		if ( count( $newGroups ) ) {
			// Array_values is used here because of bug 42211
			// see use of array_unique in UserrightsPage::doSaveUserGroups on $newGroups.
			$params[4] = $lang->listToText( array_values( $newGroups ) );
		} else {
			$params[4] = $this->msg( 'rightsnone' )->text();
		}

		return $params;
	}
}
