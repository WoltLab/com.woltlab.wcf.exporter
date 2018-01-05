<?php
namespace wcf\system\exporter;
use gallery\system\GALLERYCore;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\DateUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for Burning Board 3.x
 * 
 * @author	Tim Duesterhus, Marcel Werk
 * @copyright	2001-2017 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class WBB3xExporter extends AbstractExporter {
	/**
	 * wcf installation number
	 * @var	integer
	 */
	protected $dbNo = 1;
	
	/**
	 * wbb installation number
	 * @var	integer
	 */
	protected $instanceNo = 1;
	
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = [];
	
	/**
	 * @inheritDoc
	 */
	protected $methods = [
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.wcf.user.group' => 'UserGroups',
		'com.woltlab.wcf.user.rank' => 'UserRanks',
		'com.woltlab.wcf.user.follower' => 'Followers',
		'com.woltlab.wcf.user.comment' => 'GuestbookEntries',
		'com.woltlab.wcf.user.comment.response' => 'GuestbookResponses',
		'com.woltlab.wcf.user.avatar' => 'UserAvatars',
		'com.woltlab.wcf.user.option' => 'UserOptions',
		'com.woltlab.wcf.conversation.label' => 'ConversationFolders',
		'com.woltlab.wcf.conversation' => 'Conversations',
		'com.woltlab.wcf.conversation.message' => 'ConversationMessages',
		'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
		'com.woltlab.wcf.conversation.attachment' => 'ConversationAttachments',
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
		'com.woltlab.wbb.attachment' => 'PostAttachments',
		'com.woltlab.wbb.watchedThread' => 'WatchedThreads',
		'com.woltlab.wbb.poll' => 'Polls',
		'com.woltlab.wbb.poll.option' => 'PollOptions',
		'com.woltlab.wbb.poll.option.vote' => 'PollOptionVotes',
		'com.woltlab.wbb.like' => 'ThreadRatings',
		'com.woltlab.wcf.label' => 'Labels',
		'com.woltlab.wbb.acl' => 'ACLs',
		'com.woltlab.wcf.smiley.category' => 'SmileyCategories',
		'com.woltlab.wcf.smiley' => 'Smilies',
		
		'com.woltlab.blog.category' => 'BlogCategories',
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.like' => 'BlogEntryLikes',
		
		'com.woltlab.gallery.category' => 'GalleryCategories',
		'com.woltlab.gallery.album' => 'GalleryAlbums',
		'com.woltlab.gallery.image' => 'GalleryImages',
		'com.woltlab.gallery.image.comment' => 'GalleryComments',
		'com.woltlab.gallery.image.like' => 'GalleryImageLikes',
		
		'com.woltlab.calendar.category' => 'CalendarCategories',
		'com.woltlab.calendar.event' => 'CalendarEvents',
		'com.woltlab.calendar.event.attachment' => 'CalendarAttachments',
		'com.woltlab.calendar.event.date' => 'CalendarEventDates',
		'com.woltlab.calendar.event.date.comment' => 'CalendarEventDateComments',
		'com.woltlab.calendar.event.date.participation' => 'CalendarEventDateParticipation'
	];
	
	/**
	 * @inheritDoc
	 */
	protected $limits = [
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.conversation.attachment' => 100,
		'com.woltlab.wbb.attachment' => 100,
		'com.woltlab.wbb.acl' => 50,
		'com.woltlab.gallery.image' => 100
	];
	
	/**
	 * valid thread sort fields
	 * @var	array<string>
	 */
	protected static $availableThreadSortFields = ['topic', 'username', 'time', 'views', 'replies', 'lastPostTime', 'cumulativeLikes'];
	
	/**
	 * @inheritDoc
	 */
	public function init() {
		parent::init();
		
		if (preg_match('/^wbb(\d+)_(\d+)_$/', $this->databasePrefix, $match)) {
			$this->dbNo = $match[1];
			$this->instanceNo = $match[2];
		}
		
		// fix file system path
		if (!empty($this->fileSystemPath)) {
			if (!@file_exists($this->fileSystemPath . 'lib/core.functions.php') && @file_exists($this->fileSystemPath . 'wcf/lib/core.functions.php')) {
				$this->fileSystemPath = $this->fileSystemPath . 'wcf/';
			}
		}
	}
	
	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return [
			'com.woltlab.wcf.user' => [
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			],
			'com.woltlab.wbb.board' => [
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'
			],
			'com.woltlab.wcf.conversation' => [
				'com.woltlab.wcf.conversation.attachment',
				'com.woltlab.wcf.conversation.label'
			],
			'com.woltlab.blog.entry' => [
				'com.woltlab.blog.category',
				'com.woltlab.blog.entry.attachment',
				'com.woltlab.blog.entry.comment',
				'com.woltlab.blog.entry.like'
			],
			'com.woltlab.gallery.image' => [
				'com.woltlab.gallery.category',
				'com.woltlab.gallery.album',
				'com.woltlab.gallery.image.comment',
				'com.woltlab.gallery.image.like'
			],
			'com.woltlab.calendar.event' => [
				'com.woltlab.calendar.category',
				'com.woltlab.calendar.event.attachment',
				'com.woltlab.calendar.event.date.participation'
			],
			'com.woltlab.wcf.smiley' => []
		];
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_post";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData) || in_array('com.woltlab.blog.entry.attachment', $this->selectedData) || in_array('com.woltlab.calendar.event.attachment', $this->selectedData) || in_array('com.woltlab.gallery.image', $this->selectedData)) {
			if (empty($this->fileSystemPath) || (!@file_exists($this->fileSystemPath . 'lib/core.functions.php') && !@file_exists($this->fileSystemPath . 'wcf/lib/core.functions.php'))) return false;
		}
		
		return true;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getQueue() {
		$queue = [];
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			if (in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.group';
				if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';
			}
			if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
			if ($this->getPackageVersion('com.woltlab.wcf.user.guestbook')) {
				if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
					$queue[] = 'com.woltlab.wcf.user.comment';
					$queue[] = 'com.woltlab.wcf.user.comment.response';
				}
			}
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
					
				if (in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.attachment';
			}
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			if (in_array('com.woltlab.wcf.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.label';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
			if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';
		}
		
		// smiley
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (substr($this->getPackageVersion('com.woltlab.wcf.data.message.bbcode'), 0, 3) == '1.1') {
				$queue[] = 'com.woltlab.wcf.smiley.category';
			}
			$queue[] = 'com.woltlab.wcf.smiley';
		}
		
		// blog
		if (substr($this->getPackageVersion('com.woltlab.wcf.user.blog'), 0, 3) == '1.1') {
			if (in_array('com.woltlab.blog.entry', $this->selectedData)) {
				if (in_array('com.woltlab.blog.category', $this->selectedData)) $queue[] = 'com.woltlab.blog.category';
				$queue[] = 'com.woltlab.blog.entry';
				if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.attachment';
				if (in_array('com.woltlab.blog.entry.comment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.comment';
				if (in_array('com.woltlab.blog.entry.like', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.like';
			}
		}
		
		// gallery
		if (substr($this->getPackageVersion('com.woltlab.wcf.user.gallery'), 0, 3) == '1.1') {
			if (in_array('com.woltlab.gallery.image', $this->selectedData)) {
				if (in_array('com.woltlab.gallery.category', $this->selectedData)) $queue[] = 'com.woltlab.gallery.category';
				if (in_array('com.woltlab.gallery.album', $this->selectedData)) $queue[] = 'com.woltlab.gallery.album';
				$queue[] = 'com.woltlab.gallery.image';
				if (in_array('com.woltlab.gallery.image.comment', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.comment';
				if (in_array('com.woltlab.gallery.image.like', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.like';
			}
		}
		
		// calendar
		if ($this->getPackageVersion('com.woltlab.wcal.core')) {
			if (in_array('com.woltlab.calendar.event', $this->selectedData)) {
				if (in_array('com.woltlab.calendar.category', $this->selectedData)) $queue[] = 'com.woltlab.calendar.category';
				$queue[] = 'com.woltlab.calendar.event';
				$queue[] = 'com.woltlab.calendar.event.date';
				$queue[] = 'com.woltlab.calendar.event.date.comment';
				if (in_array('com.woltlab.calendar.event.attachment', $this->selectedData)) $queue[] = 'com.woltlab.calendar.event.attachment';
				if (in_array('com.woltlab.calendar.event.date.participation', $this->selectedData)) $queue[] = 'com.woltlab.calendar.event.date.participation';
			}
		}
		
		return $queue;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDefaultDatabasePrefix() {
		return 'wbb1_1_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		return $this->__getMaxID("wcf".$this->dbNo."_group", 'groupID');
	}
	
	/**
	 * Exports user groups.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_group
			WHERE		groupID BETWEEN ? AND ?
			ORDER BY	groupID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['groupID'], [
				'groupName' => $row['groupName'],
				'groupType' => $row['groupType'],
				'userOnlineMarking' => !empty($row['userOnlineMarking']) ? $row['userOnlineMarking'] : '',
				'showOnTeamPage' => !empty($row['showOnTeamPage']) ? $row['showOnTeamPage'] : 0
			]);
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID("wcf".$this->dbNo."_user", 'userID');
	}
	
	/**
	 * Exports users.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUsers($offset, $limit) {
		// cache existing user options
		$existingUserOptions = [];
		$sql = "SELECT	optionName, optionID
			FROM	wcf".WCF_N."_user_option
			WHERE	optionName NOT LIKE 'option%'";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$existingUserOptions[$row['optionName']] = true;
		}
		
		// cache user options
		$userOptions = [];
		$sql = "SELECT	optionName, optionID
			FROM	wcf".$this->dbNo."_user_option";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$userOptions[$row['optionID']] = (isset($existingUserOptions[$row['optionName']]) ? $row['optionName'] : $row['optionID']);
		}
		
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get password encryption
		$encryption = 'wcf1';
		$sql = "SELECT	optionName, optionValue
			FROM	wcf".$this->dbNo."_option
			WHERE	optionName IN ('encryption_enable_salting', 'encryption_encrypt_before_salting', 'encryption_method', 'encryption_salt_position')";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$encryptionData = [];
		while ($row = $statement->fetchArray()) {
			$encryptionData[$row['optionName']] = $row['optionValue'];
		}
		
		if (isset($encryptionData['encryption_method']) && in_array($encryptionData['encryption_method'], ['crc32', 'md5', 'sha1'])) {
			if ($encryptionData['encryption_enable_salting'] && $encryptionData['encryption_encrypt_before_salting'] && $encryptionData['encryption_method'] == 'sha1' && $encryptionData['encryption_salt_position'] == 'before') {
				$encryption = 'wcf1';
			}
			else {
				$encryption = 'wcf1e'.substr($encryptionData['encryption_method'], 0, 1);
				$encryption .= $encryptionData['encryption_enable_salting'];
				$encryption .= ($encryptionData['encryption_salt_position'] == 'after' ? 'a' : 'b');
				$encryption .= $encryptionData['encryption_encrypt_before_salting'];
			}
		}
		
		// get users
		$sql = "SELECT		user_option_value.*, user_table.*,
					(
						SELECT	GROUP_CONCAT(groupID)
						FROM	wcf".$this->dbNo."_user_to_groups
						WHERE	userID = user_table.userID
					) AS groupIDs,
					(
						SELECT		GROUP_CONCAT(language.languageCode)
						FROM		wcf".$this->dbNo."_user_to_languages user_to_languages
						LEFT JOIN	wcf".$this->dbNo."_language language
						ON		(language.languageID = user_to_languages.languageID)
						WHERE		user_to_languages.userID = user_table.userID
					) AS languageCodes
			FROM		wcf".$this->dbNo."_user user_table
			LEFT JOIN	wcf".$this->dbNo."_user_option_value user_option_value
			ON		(user_option_value.userID = user_table.userID)
			WHERE		user_table.userID BETWEEN ? AND ?
			ORDER BY	user_table.userID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['registrationDate'],
				'banned' => $row['banned'],
				'banReason' => $row['banReason'],
				'activationCode' => $row['activationCode'],
				'oldUsername' => $row['oldUsername'],
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['registrationIpAddress']),
				'disableAvatar' => $row['disableAvatar'],
				'disableAvatarReason' => !empty($row['disableAvatarReason']) ? $row['disableAvatarReason'] : '',
				'enableGravatar' => (!empty($row['gravatar']) && $row['gravatar'] == $row['email']) ? 1 : 0,
				'signature' => $row['signature'],
				'signatureEnableHtml' => $row['enableSignatureHtml'],
				'disableSignature' => $row['disableSignature'],
				'disableSignatureReason' => $row['disableSignatureReason'],
				'profileHits' => $row['profileHits'],
				'userTitle' => $row['userTitle'],
				'lastActivityTime' => $row['lastActivityTime']
			];
			$additionalData = [
				'groupIDs' => explode(',', $row['groupIDs']),
				'languages' => explode(',', $row['languageCodes']),
				'options' => []
			];
			
			// handle user options
			foreach ($userOptions as $optionID => $optionName) {
				if ($optionName == 'timezone') continue; // skip broken timezone setting
				
				if (isset($row['userOption'.$optionID])) {
					$additionalData['options'][$optionName] = $row['userOption'.$optionID];
				}
			}
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userID'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute([$encryption.':'.$row['password'].':'.$row['salt'], $newUserID]);
			}
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_rank", 'rankID');
	}
	
	/**
	 * Exports user ranks.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserRanks($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_rank
			WHERE		rankID BETWEEN ? AND ?
			ORDER BY	rankID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['rankID'], [
				'groupID' => $row['groupID'],
				'requiredPoints' => $row['neededPoints'],
				'rankTitle' => $row['rankTitle'],
				'rankImage' => $row['rankImage'],
				'repeatImage' => $row['repeatImage'],
				'requiredGender' => $row['gender']
			]);
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_whitelist";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_whitelist
			ORDER BY	userID, whiteUserID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, [
				'userID' => $row['userID'],
				'followUserID' => $row['whiteUserID'],
				'time' => !empty($row['time']) ? $row['time'] : 0
			]);
		}
	}
	
	/**
	 * Counts guestbook entries.
	 */
	public function countGuestbookEntries() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_guestbook", 'entryID');
	}
	
	/**
	 * Exports guestbook entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGuestbookEntries($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_guestbook
			WHERE		entryID BETWEEN ? AND ?
			ORDER BY	entryID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['entryID'], [
				'objectID' => $row['ownerID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts guestbook responses.
	 */
	public function countGuestbookResponses() {
		$sql = "SELECT	MAX(entryID) AS maxID
			FROM	wcf".$this->dbNo."_user_guestbook
			WHERE	commentTime > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0]);
		$row = $statement->fetchArray();
		if ($row !== false) return $row['maxID'];
		return 0;
	}
	
	/**
	 * Exports guestbook responses.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGuestbookResponses($offset, $limit) {
		$sql = "SELECT		user_guestbook.*, user_table.username AS ownerName
			FROM		wcf".$this->dbNo."_user_guestbook user_guestbook
			LEFT JOIN	wcf".$this->dbNo."_user user_table
			ON		(user_table.userID = user_guestbook.ownerID)
			WHERE		user_guestbook.commentTime > ?
					AND user_guestbook.entryID BETWEEN ? AND ?
			ORDER BY	user_guestbook.entryID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0, $offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment.response')->import($row['entryID'], [
				'commentID' => $row['entryID'],
				'time' => $row['commentTime'],
				'userID' => $row['ownerID'],
				'username' => $row['ownerName'],
				'message' => $row['comment'],
			]);
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	MAX(avatarID) AS maxID
			FROM	wcf".$this->dbNo."_avatar
			WHERE	userID <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0]);
		$row = $statement->fetchArray();
		if ($row !== false) return $row['maxID'];
		return 0;
	}
	
	/**
	 * Exports user avatars.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_avatar
			WHERE		userID <> ?
					AND avatarID BETWEEN ? AND ?
			ORDER BY	avatarID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0, $offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['avatarID'], [
				'avatarName' => $row['avatarName'],
				'avatarExtension' => $row['avatarExtension'],
				'width' => $row['width'],
				'height' => $row['height'],
				'userID' => $row['userID']
			], ['fileLocation' => $this->fileSystemPath . 'images/avatars/avatar-' . $row['avatarID'] . '.' . $row['avatarExtension']]);
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		// get existing option names
		$optionsNames = $this->getExistingUserOptions();
		
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('categoryName IN (SELECT categoryName FROM wcf'.$this->dbNo.'_user_option_category WHERE parentCategoryName = ?)', ['profile']);
		if (!empty($optionsNames)) $conditionBuilder->add('optionName NOT IN (?)', [$optionsNames]);
		
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_option
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports user options.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserOptions($offset, $limit) {
		// get existing option names
		$optionsNames = $this->getExistingUserOptions();
		
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('categoryName IN (SELECT categoryName FROM wcf'.$this->dbNo.'_user_option_category WHERE parentCategoryName = ?)', ['profile']);
		if (!empty($optionsNames)) $conditionBuilder->add('optionName NOT IN (?)', [$optionsNames]);
		
		$sql = "SELECT	user_option.*, (
					SELECT	languageItemValue
					FROM	wcf".$this->dbNo."_language_item
					WHERE	languageItem = CONCAT('wcf.user.option.', user_option.optionName)
						AND languageID = (
							SELECT	languageID
							FROM	wcf".$this->dbNo."_language
							WHERE	isDefault = 1
						)
					LIMIT	1
				) AS name
			FROM	wcf".$this->dbNo."_user_option user_option
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$editable = 0;
			switch ($row['editable']) {
				case 0:
					$editable = UserOption::EDITABILITY_ALL;
					break;
				case 1:
				case 2:
					$editable = UserOption::EDITABILITY_OWNER;
					break;
				case 3:
					$editable = UserOption::EDITABILITY_ADMINISTRATOR;
					break;
			}
			
			$visible = 0;
			switch ($row['visible']) {
				case 0:
					$visible = UserOption::VISIBILITY_ALL;
					break;
				case 1:
					$visible = UserOption::VISIBILITY_OWNER | UserOption::VISIBILITY_ADMINISTRATOR;
					break;
				case 2:
					$visible = UserOption::VISIBILITY_OWNER;
					break;
				case 3:
					$visible = UserOption::VISIBILITY_ADMINISTRATOR;
					break;
			}
			
			// fix option types
			switch ($row['optionType']) {
				case 'multiselect':
					$row['optionType'] = 'multiSelect';
					break;
				case 'radiobuttons':
					$row['optionType'] = 'radioButton';
					break;
					
				case 'birthday':
				case 'boolean':
				case 'date':
				case 'integer':
				case 'float':
				case 'password':
				case 'select':
				case 'text':
				case 'textarea':
				case 'message':
					break;
				
				default:
					$row['optionType'] = 'textarea';
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['optionID'], [
				'categoryName' => $row['categoryName'],
				'optionType' => $row['optionType'],
				'defaultValue' => $row['defaultValue'],
				'validationPattern' => $row['validationPattern'],
				'selectOptions' => $row['selectOptions'],
				'required' => $row['required'],
				'askDuringRegistration' => !empty($row['askDuringRegistration']) ? 1 : 0,
				'searchable' => $row['searchable'],
				'isDisabled' => $row['disabled'],
				'editable' => $editable,
				'visible' => $visible,
				'showOrder' => $row['showOrder']
			], ['name' => $row['name'] ?: $row['optionName']]);
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		return $this->__getMaxID("wcf".$this->dbNo."_pm_folder", 'folderID');
	}
	
	/**
	 * Exports conversation folders.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_pm_folder
			WHERE		folderID BETWEEN ? AND ?
			ORDER BY	folderID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$cssClassName = '';
			if (!empty($row['color'])) {
				switch ($row['color']) {
					case 'yellow':
					case 'red':
					case 'blue':
					case 'green':
						$cssClassName = $row['color'];
						break;
				}
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['folderID'], [
				'userID' => $row['userID'],
				'label' => mb_substr($row['folderName'], 0, 80),
				'cssClassName' => $cssClassName
			]);
		}
	}
	
	/**
	 * Creates a conversation id out of the old parentPmID and the participants.
	 * 
	 * This ensures that only the actual receivers of a pm are able to see it
	 * after import, while minimizing the number of conversations.
	 *
	 * @param	integer		$parentPmID
	 * @param	integer[]	$participants
	 * @return	string
	 */
	private function getConversationID($parentPmID, array $participants) {
		$conversationID = $parentPmID;
		$participants = array_unique($participants);
		sort($participants);
		$conversationID .= '-'.implode(',', $participants);
		
		return StringUtil::getHash($conversationID);
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		return $this->__getMaxID("wcf".$this->dbNo."_pm", 'pmID');
	}
	
	/**
	 * Exports conversations.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		pm.*,
					(
						SELECT	GROUP_CONCAT(pm_to_user.recipientID)
						FROM	wcf".$this->dbNo."_pm_to_user pm_to_user
						WHERE	pm_to_user.pmID = pm.pmID
					) AS participants
			FROM		wcf".$this->dbNo."_pm pm
			WHERE		pm.pmID BETWEEN ? AND ?
			ORDER BY	pm.pmID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$participants = explode(',', $row['participants']);
			$participants[] = $row['userID'];
			$conversationID = $this->getConversationID($row['parentPmID'] ?: $row['pmID'], $participants);
			
			if (ImportHandler::getInstance()->getNewID('com.woltlab.wcf.conversation', $conversationID) !== null) continue;
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($conversationID, [
				'subject' => $row['subject'],
				'time' => $row['time'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'isDraft' => $row['isDraft']
			]);
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		return $this->__getMaxID("wcf".$this->dbNo."_pm", 'pmID');
	}
	
	/**
	 * Exports conversation messages.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		pm.*,
					(
						SELECT	GROUP_CONCAT(pm_to_user.recipientID)
						FROM	wcf".$this->dbNo."_pm_to_user pm_to_user
						WHERE	pm_to_user.pmID = pm.pmID
					) AS participants
			FROM		wcf".$this->dbNo."_pm pm
			WHERE		pm.pmID BETWEEN ? AND ?
			ORDER BY	pm.pmID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$participants = explode(',', $row['participants']);
			$participants[] = $row['userID'];
			$conversationID = $this->getConversationID($row['parentPmID'] ?: $row['pmID'], $participants);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['pmID'], [
				'conversationID' => $conversationID,
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'attachments' => $row['attachments'],
				'enableHtml' => $row['enableHtml']
			]);
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_pm_to_user";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		pm_to_user.*,
					pm.isDraft,
					pm.parentPmID,
					(
						SELECT	GROUP_CONCAT(pm_to_user.recipientID)
						FROM	wcf".$this->dbNo."_pm_to_user pm_to_user
						WHERE	pm_to_user.pmID = pm.pmID
					) AS participants,
					pm.userID AS sender
			FROM		wcf".$this->dbNo."_pm_to_user pm_to_user
			FORCE INDEX(PRIMARY)
			LEFT JOIN	wcf".$this->dbNo."_pm pm
			ON		(pm.pmID = pm_to_user.pmID)
			ORDER BY	pm_to_user.pmID DESC, pm_to_user.recipientID DESC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$participants = explode(',', $row['participants']);
			$participants[] = $row['sender'];
			$conversationID = $this->getConversationID($row['parentPmID'] ?: $row['pmID'], $participants);
			
			if ($row['isDraft']) continue;
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, [
				'conversationID' => $conversationID,
				'participantID' => $row['recipientID'],
				'username' => $row['recipient'],
				'hideConversation' => $row['isDeleted'],
				'isInvisible' => $row['isBlindCopy'],
				'lastVisitTime' => $row['isViewed']
			], ['labelIDs' => $row['folderID'] ? [$row['folderID']] : []]);
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		return $this->countAttachments('pm');
	}
	
	/**
	 * Exports conversation attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationAttachments($offset, $limit) {
		$this->exportAttachments('pm', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_board";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports boards.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		board.*, structure.position
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_board board
			LEFT JOIN	wbb".$this->dbNo."_".$this->instanceNo."_board_structure structure
			ON		(structure.boardID = board.boardID)
			ORDER BY	board.parentID, structure.position";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parentID']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 *
	 * @param	integer		$parentID
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			if (!in_array($board['sortField'], self::$availableThreadSortFields)) $board['sortField'] = 'lastPostTime';
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['boardID'], [
				'parentID' => $board['parentID'] ?: null,
				'position' => $board['position'] ?: 0,
				'boardType' => $board['boardType'],
				'title' => $board['title'],
				'description' => $board['description'],
				'descriptionUseHtml' => $board['allowDescriptionHtml'],
				'externalURL' => $board['externalURL'],
				'time' => $board['time'],
				'countUserPosts' => $board['countUserPosts'],
				'daysPrune' => $board['daysPrune'],
				'enableMarkingAsDone' => !empty($board['enableMarkingAsDone']) ? $board['enableMarkingAsDone'] : 0,
				'ignorable' => !empty($board['ignorable']) ? $board['ignorable'] : 0,
				'isClosed' => $board['isClosed'],
				'isInvisible' => $board['isInvisible'],
				'postSortOrder' => !empty($board['postSortOrder']) ? $board['postSortOrder'] : '',
				'postsPerPage' => !empty($board['postsPerPage']) ? $board['postsPerPage'] : 0,
				'searchable' => isset($board['searchable']) ? intval($board['searchable']) : 1,
				'searchableForSimilarThreads' => !empty($board['searchableForSimilarThreads']) ? $board['searchableForSimilarThreads'] : 0,
				'showSubBoards' => $board['showSubBoards'],
				'sortField' => $board['sortField'],
				'sortOrder' => $board['sortOrder'],
				'threadsPerPage' => !empty($board['threadsPerPage']) ? $board['threadsPerPage'] : 0,
				'clicks' => $board['clicks'],
				'posts' => $board['posts'],
				'threads' => $board['threads']
			]);
			
			$this->exportBoardsRecursively($board['boardID']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID("wbb".$this->dbNo."_".$this->instanceNo."_thread", 'threadID');
	}
	
	/**
	 * Exports threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportThreads($offset, $limit) {
		// get global prefixes
		$globalPrefixes = '';
		$sql = "SELECT	optionValue
			FROM	wcf".$this->dbNo."_option
			WHERE	optionName = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['thread_default_prefixes']);
		$row = $statement->fetchArray();
		if ($row !== false) $globalPrefixes = $row['optionValue'];
		
		// get boards
		$boardPrefixes = [];
		
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1') {
			$sql = "SELECT		boardID, prefixes, prefixMode
				FROM		wbb".$this->dbNo."_".$this->instanceNo."_board
				WHERE		prefixMode > ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute([0]);
		}
		else {
			$sql = "SELECT		boardID, prefixes, 2 AS prefixMode
				FROM		wbb".$this->dbNo."_".$this->instanceNo."_board
				WHERE		prefixes <> ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(['']);
		}
		
		while ($row = $statement->fetchArray()) {
			$prefixes = '';
			
			switch ($row['prefixMode']) {
				case 1:
					$prefixes = $globalPrefixes;
					break;
				case 2:
					$prefixes = $row['prefixes'];
					break;
				case 3:
					$prefixes = $globalPrefixes . "\n" . $row['prefixes'];
					break;
			}
			
			$prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
			if ($prefixes) {
				$key = StringUtil::getHash($prefixes);
				$boardPrefixes[$row['boardID']] = $key;
			}
		}
		
		// get thread ids
		$threadIDs = $announcementIDs = [];
		$sql = "SELECT		threadID, isAnnouncement
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread
			WHERE		threadID BETWEEN ? AND ?
			ORDER BY	threadID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$threadIDs[] = $row['threadID'];
			if ($row['isAnnouncement']) $announcementIDs[] = $row['threadID'];
		}
		if (empty($threadIDs)) return;
		
		// get assigned boards (for announcements)
		$assignedBoards = [];
		if (!empty($announcementIDs)) {
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('threadID IN (?)', [$announcementIDs]);
			
			$sql = "SELECT		boardID, threadID
				FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread_announcement
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				if (!isset($assignedBoards[$row['threadID']])) $assignedBoards[$row['threadID']] = [];
				$assignedBoards[$row['threadID']][] = $row['boardID'];
			}
		}
		
		// get tags
		$tags = $this->getTags('com.woltlab.wbb.thread', $threadIDs);
		
		// get threads
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('threadID IN (?)', [$threadIDs]);
		
		$sql = "SELECT		thread.*, language.languageCode
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread thread
			LEFT JOIN	wcf".$this->dbNo."_language language
			ON		(language.languageID = thread.languageID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$data = [
				'boardID' => $row['boardID'],
				'topic' => $row['topic'],
				'time' => $row['time'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'views' => $row['views'],
				'isAnnouncement' => $row['isAnnouncement'],
				'isSticky' => $row['isSticky'],
				'isDisabled' => $row['isDisabled'],
				'isClosed' => $row['isClosed'],
				'isDeleted' => $row['isDeleted'],
				'movedThreadID' => $row['movedThreadID'] ?: null,
				'movedTime' => !empty($row['movedTime']) ? $row['movedTime'] : 0,
				'isDone' => !empty($row['isDone']) ? $row['isDone'] : 0,
				'deleteTime' => $row['deleteTime'],
				'lastPostTime' => $row['lastPostTime']
			];
			$additionalData = [];
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			if (!empty($assignedBoards[$row['threadID']])) $additionalData['assignedBoards'] = $assignedBoards[$row['threadID']];
			if ($row['prefix'] && isset($boardPrefixes[$row['boardID']])) $additionalData['labels'] = [$boardPrefixes[$row['boardID']].'-'.$row['prefix']];
			if (isset($tags[$row['threadID']])) $additionalData['tags'] = $tags[$row['threadID']];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['threadID'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID("wbb".$this->dbNo."_".$this->instanceNo."_post", 'postID');
	}
	
	/**
	 * Exports posts.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		*
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_post
			WHERE		postID BETWEEN ? AND ?
			ORDER BY	postID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['postID'], [
				'threadID' => $row['threadID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'isDeleted' => $row['isDeleted'],
				'isDisabled' => $row['isDisabled'],
				'isClosed' => $row['isClosed'],
				'editorID' => $row['editorID'] ?: null,
				'editor' => $row['editor'],
				'lastEditTime' => $row['lastEditTime'],
				'editCount' => $row['editCount'],
				'editReason' => !empty($row['editReason']) ? $row['editReason'] : '',
				'attachments' => $row['attachments'],
				'enableHtml' => $row['enableHtml'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ipAddress']),
				'deleteTime' => $row['deleteTime']
			]);
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments('post');
	}
	
	/**
	 * Exports post attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('post', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_thread_subscription";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread_subscription
			ORDER BY	userID, threadID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, [
				'objectID' => $row['threadID'],
				'userID' => $row['userID'],
				'notification' => $row['enableNotification']
			]);
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	MAX(pollID) AS maxID
			FROM	wcf".$this->dbNo."_poll
			WHERE	messageType = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['post']);
		$row = $statement->fetchArray();
		if ($row !== false) return $row['maxID'];
		return 0;
	}
	
	/**
	 * Exports polls.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_poll
			WHERE		messageType = ?
					AND pollID BETWEEN ? AND ?
			ORDER BY	pollID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['post', $offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollID'], [
				'objectID' => $row['messageID'],
				'question' => $row['question'],
				'time' => $row['time'],
				'endTime' => $row['endTime'] > 2147483647 ? 2147483647 : $row['endTime'],
				'isChangeable' => $row['votesNotChangeable'] ? 0 : 1,
				'isPublic' => !empty($row['isPublic']) ? $row['isPublic'] : 0,
				'sortByVotes' => $row['sortByResult'],
				'maxVotes' => $row['choiceCount'],
				'votes' => $row['votes']
			]);
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	MAX(pollOptionID) AS maxID
			FROM	wcf".$this->dbNo."_poll_option
			WHERE	pollID IN (
					SELECT	pollID
					FROM	wcf".$this->dbNo."_poll
					WHERE	messageType = ?
				)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['post']);
		$row = $statement->fetchArray();
		if ($row !== false) return $row['maxID'];
		return 0;
	}
	
	/**
	 * Exports poll options.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_poll_option
			WHERE		pollID IN (
						SELECT	pollID
						FROM	wcf".$this->dbNo."_poll
						WHERE	messageType = ?
					)
					AND pollOptionID BETWEEN ? AND ?
			ORDER BY	pollOptionID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['post', $offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pollOptionID'], [
				'pollID' => $row['pollID'],
				'optionValue' => $row['pollOption'],
				'showOrder' => $row['showOrder'],
				'votes' => $row['votes']
			]);
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_poll_option_vote
			WHERE	pollID IN (
					SELECT	pollID
					FROM	wcf".$this->dbNo."_poll
					WHERE	messageType = ?
				)
				AND userID <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['post', 0]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_poll_option_vote
			WHERE		pollID IN (
						SELECT	pollID
						FROM	wcf".$this->dbNo."_poll
						WHERE	messageType = ?
					)
					AND userID <> ?
			ORDER BY	pollOptionID, userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['post', 0]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, [
				'pollID' => $row['pollID'],
				'optionID' => $row['pollOptionID'],
				'userID' => $row['userID']
			]);
		}
	}
	
	/**
	 * Counts thread ratings.
	 */
	public function countThreadRatings() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_".$this->instanceNo."_thread_rating
			WHERE	userID <> ?
				AND rating NOT IN (?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0, 0, 3]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports thread ratings.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportThreadRatings($offset, $limit) {
		$sql = "SELECT		thread_rating.*, thread.firstPostID, thread.userID AS objectUserID,
					thread.time
			FROM		wbb".$this->dbNo."_".$this->instanceNo."_thread_rating thread_rating
			LEFT JOIN	wbb".$this->dbNo."_".$this->instanceNo."_thread thread
			ON		(thread.threadID = thread_rating.threadID)
			WHERE		thread_rating.userID <> ?
					AND thread_rating.rating NOT IN (?, ?)
			ORDER BY	thread_rating.threadID, thread_rating.userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([0, 0, 3]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, [
				'objectID' => $row['firstPostID'],
				'objectUserID' => $row['objectUserID'] ?: null,
				'userID' => $row['userID'],
				'likeValue' => ($row['rating'] > 3) ? Like::LIKE : Like::DISLIKE,
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1') {
			$sql = "SELECT	COUNT(*) AS count
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board
				WHERE	prefixMode > ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute([0]);
		}
		else {
			$sql = "SELECT	COUNT(*) AS count
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board
				WHERE	prefixes <> ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(['']);
		}
		
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports labels.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportLabels($offset, $limit) {
		$prefixMap = [];
		
		// get global prefixes
		$globalPrefixes = '';
		$sql = "SELECT	optionValue
			FROM	wcf".$this->dbNo."_option
			WHERE	optionName = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['thread_default_prefixes']);
		$row = $statement->fetchArray();
		if ($row !== false) $globalPrefixes = $row['optionValue'];
		
		// get boards
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1') {
			$sql = "SELECT		boardID, prefixes, prefixMode
				FROM		wbb".$this->dbNo."_".$this->instanceNo."_board
				WHERE		prefixMode > ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute([0]);
		}
		else {
			$sql = "SELECT		boardID, prefixes, 2 AS prefixMode
				FROM		wbb".$this->dbNo."_".$this->instanceNo."_board
				WHERE		prefixes <> ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(['']);
		}
		
		while ($row = $statement->fetchArray()) {
			$prefixes = '';
			
			switch ($row['prefixMode']) {
				case 1:
					$prefixes = $globalPrefixes;
					break;
				case 2:
					$prefixes = $row['prefixes'];
					break;
				case 3:
					$prefixes = $globalPrefixes . "\n" . $row['prefixes'];
					break;
			}
			
			$prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
			if ($prefixes) {
				$key = StringUtil::getHash($prefixes);
				if (!isset($prefixMap[$key])) {
					$prefixMap[$key] = [
						'prefixes' => $prefixes,
						'boardIDs' => []
					];
				}
				
				$boardID = ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $row['boardID']);
				if ($boardID) $prefixMap[$key]['boardIDs'][] = $boardID;
			}
		}
		
		// save prefixes
		if (!empty($prefixMap)) {
			$i = 1;
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
			
			foreach ($prefixMap as $key => $data) {
				// import label group
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($key, [
					'groupName' => 'labelgroup'.$i
				], ['objects' => [$objectType->objectTypeID => $data['boardIDs']]]);
				
				// import labels
				$labels = explode("\n", $data['prefixes']);
				foreach ($labels as $label) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($key.'-'.$label, [
						'groupID' => $key,
						'label' => mb_substr($label, 0, 80)
					]);
				}
				
				$i++;
			}
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	(SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_board_moderator)
				+ (SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_board_to_group)
				+ (SELECT COUNT(*) FROM wbb".$this->dbNo."_".$this->instanceNo."_board_to_user) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports ACLs.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportACLs($offset, $limit) {
		// get ids
		$mod = $user = $group = [];
		$sql = "(
				SELECT	boardID, userID, groupID, 'mod' AS type
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_moderator
				WHERE	userID <> 0
			)
			UNION
			(
				SELECT	boardID, 0 AS userID, groupID, 'group' AS type
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_group
			)
			UNION
			(
				SELECT	boardID, userID, 0 AS groupID, 'user' AS type
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_user
			)
			ORDER BY	boardID, userID, groupID, type";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			/** @noinspection PhpVariableVariableInspection */
			${$row['type']}[] = $row;
		}
		
		// mods
		if (!empty($mod)) {
			$conditionBuilder = new PreparedStatementConditionBuilder(true, 'OR');
			foreach ($mod as $row) {
				$conditionBuilder->add('(boardID = ? AND userID = ? AND groupID = ?)', [$row['boardID'], $row['userID'], $row['groupID']]);
			}
			
			$sql = "SELECT	*
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_moderator
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				$data = [
					'objectID' => $row['boardID']
				];
				if ($row['userID']) $data['userID'] = $row['userID'];
				else if ($row['groupID']) $data['groupID'] = $row['groupID'];
				
				unset($row['boardID'], $row['userID'], $row['groupID']);
				
				foreach ($row as $permission => $value) {
					if ($value == -1) continue;
					
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, ['optionValue' => $value]), ['optionName' => $permission]);
				}
			}
		}
		
		// groups
		if (!empty($group)) {
			$conditionBuilder = new PreparedStatementConditionBuilder(true, 'OR');
			foreach ($group as $row) {
				$conditionBuilder->add('(boardID = ? AND groupID = ?)', [$row['boardID'], $row['groupID']]);
			}
			
			$sql = "SELECT	*
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_group
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				$data = [
					'objectID' => $row['boardID']
				];
				$data['groupID'] = $row['groupID'];
				
				unset($row['boardID'], $row['groupID']);
				
				foreach ($row as $permission => $value) {
					if ($value == -1) continue;
					
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, ['optionValue' => $value]), ['optionName' => $permission]);
				}
			}
		}
		
		// users
		if (!empty($user)) {
			$conditionBuilder = new PreparedStatementConditionBuilder(true, 'OR');
			foreach ($user as $row) {
				$conditionBuilder->add('(boardID = ? AND userID = ?)', [$row['boardID'], $row['userID']]);
			}
			
			$sql = "SELECT	*
				FROM	wbb".$this->dbNo."_".$this->instanceNo."_board_to_user
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				$data = [
					'objectID' => $row['boardID']
				];
				$data['userID'] = $row['userID'];
				
				unset($row['boardID'], $row['userID']);
				
				foreach ($row as $permission => $value) {
					if ($value == -1) continue;
					
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, ['optionValue' => $value]), ['optionName' => $permission]);
				}
			}
		}
	}
	
	/**
	 * Counts smilies.
	 */
	public function countSmilies() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_smiley";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports smilies.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportSmilies($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_smiley
			ORDER BY	smileyID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . $row['smileyPath'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smileyID'], [
				'smileyTitle' => $row['smileyTitle'],
				'smileyCode' => $row['smileyCode'],
				'showOrder' => $row['showOrder'],
				'categoryID' => !empty($row['smileyCategoryID']) ? $row['smileyCategoryID'] : null
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Counts smiley categories.
	 */
	public function countSmileyCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_smiley_category";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports smiley categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportSmileyCategories($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_smiley_category
			ORDER BY	smileyCategoryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley.category')->import($row['smileyCategoryID'], [
				'title' => $row['title'],
				'parentCategoryID' => 0,
				'showOrder' => $row['showOrder']
			]);
		}
	}
	
	/**
	 * Counts blog categories.
	 */
	public function countBlogCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_blog_category
			WHERE	userID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([0]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogCategories($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_blog_category
			WHERE		userID = ?
			ORDER BY	categoryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([0]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.category')->import($row['categoryID'], [
				'title' => $row['title'],
				'parentCategoryID' => 0,
				'showOrder' => 0
			]);
		}
	}
	
	/**
	 * Counts blog entries.
	 */
	public function countBlogEntries() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_blog", 'entryID');
	}
	
	/**
	 * Exports blog entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntries($offset, $limit) {
		// get entry ids
		$entryIDs = [];
		$sql = "SELECT		entryID
			FROM		wcf".$this->dbNo."_user_blog
			WHERE		entryID BETWEEN ? AND ?
			ORDER BY	entryID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$entryIDs[] = $row['entryID'];
		}
		if (empty($entryIDs)) return;
		
		// get tags
		$tags = $this->getTags('com.woltlab.wcf.user.blog.entry', $entryIDs);
		
		// get categories
		$categories = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('entry_to_category.entryID IN (?)', [$entryIDs]);
		$conditionBuilder->add('category.userID = ?', [0]);
		
		$sql = "SELECT		entry_to_category.* 
			FROM		wcf".$this->dbNo."_user_blog_entry_to_category entry_to_category
			LEFT JOIN	wcf".$this->dbNo."_user_blog_category category
			ON		(category.categoryID = entry_to_category.categoryID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['entryID']])) $categories[$row['entryID']] = [];
			$categories[$row['entryID']][] = $row['categoryID'];
		}
		
		// get entries
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('user_blog.entryID IN (?)', [$entryIDs]);
		
		$sql = "SELECT		user_blog.*, language.languageCode
			FROM		wcf".$this->dbNo."_user_blog user_blog
			LEFT JOIN	wcf".$this->dbNo."_language language
			ON		(language.languageID = user_blog.languageID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$additionalData = [];
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			if (isset($tags[$row['entryID']])) $additionalData['tags'] = $tags[$row['entryID']];
			if (isset($categories[$row['entryID']])) $additionalData['categories'] = $categories[$row['entryID']];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['entryID'], [
				'userID' => $row['userID'] ?: null,
				'username' => $row['username'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'attachments' => $row['attachments'],
				'comments' => $row['comments'],
				'enableHtml' => $row['enableHtml'],
				'views' => $row['views'],
				'isPublished' => $row['isPublished'],
				'publicationDate' => $row['publishingDate']
			], $additionalData);
		}
	}
	
	/**
	 * Counts blog attachments.
	 */
	public function countBlogAttachments() {
		return $this->countAttachments('userBlogEntry');
	}
	
	/**
	 * Exports blog attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogAttachments($offset, $limit) {
		$this->exportAttachments('userBlogEntry', 'com.woltlab.blog.entry.attachment', $offset, $limit);
	}
	
	/**
	 * Counts blog comments.
	 */
	public function countBlogComments() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_blog_comment", 'commentID');
	}
	
	/**
	 * Exports blog comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogComments($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_blog_comment
			WHERE		commentID BETWEEN ? AND ?
			ORDER BY	commentID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment')->import($row['commentID'], [
				'objectID' => $row['entryID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['comment'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts blog entry likes.
	 */
	public function countBlogEntryLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_rating
			WHERE	objectName = ?
				AND userID <> ?
				AND rating NOT IN (?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['com.woltlab.wcf.user.blog.entry', 0, 0, 3]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog entry likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntryLikes($offset, $limit) {
		$sql = "SELECT		rating.*, blog.userID AS objectUserID
			FROM		wcf".$this->dbNo."_rating rating
			LEFT JOIN	wcf".$this->dbNo."_user_blog blog
			ON		(blog.entryID = rating.objectID)
			WHERE		rating.objectName = ?
					AND rating.userID <> ?
					AND rating.rating NOT IN (?, ?)
			ORDER BY	rating.objectID, rating.userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['com.woltlab.wcf.user.blog.entry', 0, 0, 3]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.like')->import(0, [
				'objectID' => $row['objectID'],
				'objectUserID' => $row['objectUserID'] ?: null,
				'userID' => $row['userID'],
				'likeValue' => ($row['rating'] > 3) ? Like::LIKE : Like::DISLIKE
			]);
		}
	}
	
	/**
	 * Counts gallery categories.
	 */
	public function countGalleryCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_gallery_category";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports gallery categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryCategories($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_gallery_category
			ORDER BY	categoryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.category')->import($row['categoryID'], [
				'title' => $row['title'],
				'parentCategoryID' => 0,
				'showOrder' => 0
			]);
		}
	}
	
	/**
	 * Counts gallery albums.
	 */
	public function countGalleryAlbums() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_gallery_album", 'albumID');
	}
	
	/**
	 * Exports gallery albums.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryAlbums($offset, $limit) {
		$destVersion21 = version_compare(GALLERYCore::getInstance()->getPackage()->packageVersion, '2.1.0 Alpha 1', '>=');
		
		$sql = "SELECT		gallery_album.*, user_table.username
			FROM		wcf".$this->dbNo."_user_gallery_album gallery_album
			LEFT JOIN	wcf".$this->dbNo."_user user_table
			ON		(user_table.userID = gallery_album.ownerID)
			WHERE		gallery_album.albumID BETWEEN ? AND ?
			ORDER BY	albumID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'userID' => $row['ownerID'],
				'username' => $row['username'] ?: '',
				'title' => $row['title'],
				'description' => $row['description'],
				'lastUpdateTime' => $row['lastUpdateTime']
			];
			if ($destVersion21 && $row['isPrivate']) {
				$data['accessLevel'] = 2;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.album')->import($row['albumID'], $data);
		}
	}
	
	/**
	 * Counts gallery images.
	 */
	public function countGalleryImages() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_gallery", 'photoID');
	}
	
	/**
	 * Exports gallery images.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryImages($offset, $limit) {
		// get ids
		$imageIDs = [];
		$sql = "SELECT		photoID
			FROM		wcf".$this->dbNo."_user_gallery
			WHERE		photoID BETWEEN ? AND ?
			ORDER BY	photoID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$imageIDs[] = $row['photoID'];
		}
		if (empty($imageIDs)) return;
		
		// get tags
		$tags = $this->getTags('com.woltlab.wcf.user.gallery.photo', $imageIDs);
		
		// get categories
		$categories = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('photo_to_category.objectType = ?', ['photo']);
		$conditionBuilder->add('photo_to_category.objectID IN (?)', [$imageIDs]);
		
		$sql = "SELECT		photo_to_category.*
			FROM		wcf".$this->dbNo."_user_gallery_category_to_object photo_to_category
			LEFT JOIN	wcf".$this->dbNo."_user_gallery_category category
			ON		(category.categoryID = photo_to_category.categoryID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['objectID']])) $categories[$row['objectID']] = [];
			$categories[$row['objectID']][] = $row['categoryID'];
		}
		
		// get images
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('user_gallery.photoID IN (?)', [$imageIDs]);
		
		$sql = "SELECT		user_gallery.*
			FROM		wcf".$this->dbNo."_user_gallery user_gallery
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$additionalData = [
				'fileLocation' => $this->fileSystemPath . 'images/photos/photo-' . $row['photoID'] . ($row['photoHash'] ? ('-' . $row['photoHash']) : '') . '.' . $row['fileExtension']
			];
			if (isset($tags[$row['photoID']])) $additionalData['tags'] = $tags[$row['photoID']];
			if (isset($categories[$row['photoID']])) $additionalData['categories'] = array_unique($categories[$row['photoID']]);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image')->import($row['photoID'], [
				'userID' => $row['ownerID'] ?: null,
				'username' => $row['username'],
				'albumID' => $row['albumID'] ?: null,
				'title' => $row['title'],
				'description' => $row['description'],
				'filename' => $row['filename'],
				'fileExtension' => $row['fileExtension'],
				'filesize' => $row['filesize'],
				'comments' => $row['comments'],
				'views' => $row['views'],
				'uploadTime' => $row['uploadTime'],
				'creationTime' => $row['creationTime'],
				'width' => $row['width'],
				'height' => $row['height'],
				'camera' => $row['camera'],
				'latitude' => $row['latitude'],
				'longitude' => $row['longitude'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ipAddress'])
			], $additionalData);
		}
	}
	
	/**
	 * Counts gallery comments.
	 */
	public function countGalleryComments() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_gallery_comment", 'commentID');
	}
	
	/**
	 * Exports gallery comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryComments($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_gallery_comment
			WHERE		commentID BETWEEN ? AND ?
			ORDER BY	commentID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image.comment')->import($row['commentID'], [
				'objectID' => $row['photoID'],
				'userID' => $row['userID'] ?: null,
				'username' => $row['username'],
				'message' => $row['comment'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts gallery image likes.
	 */
	public function countGalleryImageLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_rating
			WHERE	objectName = ?
				AND userID <> ?
				AND rating NOT IN (?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['com.woltlab.wcf.user.gallery.photo', 0, 0, 3]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports gallery image likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryImageLikes($offset, $limit) {
		$sql = "SELECT		rating.*, photo.ownerID AS objectUserID
			FROM		wcf".$this->dbNo."_rating rating
			LEFT JOIN	wcf".$this->dbNo."_user_gallery photo
			ON		(photo.photoID = rating.objectID)
			WHERE		rating.objectName = ?
					AND rating.userID <> ?
					AND rating.rating NOT IN (?, ?)
			ORDER BY	rating.objectID, rating.userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['com.woltlab.wcf.user.gallery.photo', 0, 0, 3]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image.like')->import(0, [
				'objectID' => $row['objectID'],
				'objectUserID' => $row['objectUserID'] ?: null,
				'userID' => $row['userID'],
				'likeValue' => ($row['rating'] > 3) ? Like::LIKE : Like::DISLIKE
			]);
		}
	}
	
	/**
	 * Counts calendar categories.
	 */
	public function countCalendarCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_calendar
			WHERE	calendarID IN (SELECT calendarID FROM wcf".$this->dbNo."_calendar_to_group)
				AND className <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['BirthdayEvent']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports calendar categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarCategories($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_calendar
			WHERE		calendarID IN (SELECT calendarID FROM wcf".$this->dbNo."_calendar_to_group)
					AND className <> ?
			ORDER BY	calendarID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['BirthdayEvent']);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.category')->import($row['calendarID'], [
				'title' => $row['title'],
				'parentCategoryID' => 0,
				'showOrder' => 0
			]);
		}
	}
	
	/**
	 * Counts calendar events.
	 */
	public function countCalendarEvents() {
		return $this->__getMaxID("wcf".$this->dbNo."_calendar_event", 'eventID');
	}
	
	/**
	 * Exports calendar events.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEvents($offset, $limit) {
		$sql = "SELECT		calendar_event_participation.*, calendar_event_message.*, calendar_event.*
			FROM		wcf".$this->dbNo."_calendar_event calendar_event
			LEFT JOIN	wcf".$this->dbNo."_calendar_event_message calendar_event_message
			ON		(calendar_event_message.messageID = calendar_event.messageID)
			LEFT JOIN	wcf".$this->dbNo."_calendar_event_participation calendar_event_participation
			ON		(calendar_event_participation.eventID = calendar_event.eventID)
			WHERE		calendar_event.eventID BETWEEN ? AND ?
			ORDER BY	calendar_event.eventID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$oldEventDateData = @unserialize($row['eventDate']);
			
			$repeatEndType = 'unlimited';
			if (!empty($oldEventDateData['repeatEndTypeDate'])) $repeatEndType = 'date';
			if (!empty($oldEventDateData['repeatEndTypeCount'])) $repeatEndType = 'count';
			
			$repeatType = '';
			$repeatMonthlyByMonthDay = $repeatMonthlyByWeekDay = $repeatMonthlyDayOffset = 1;
			$repeatYearlyByMonthDay = $repeatYearlyByWeekDay = $repeatYearlyDayOffset = $repeatYearlyByMonth = 1;
			$repeatWeeklyByDay = [];
			$dateTime = DateUtil::getDateTimeByTimestamp($oldEventDateData['startTime']);
			if ($oldEventDateData['repeatType'] != 'no') {
				$repeatType = $oldEventDateData['repeatType'];
				if ($repeatType == 'weekly') {
					if (!empty($oldEventDateData['repeatByDay']) && is_array($oldEventDateData['repeatByDay'])) {
						$repeatWeeklyByDay = $oldEventDateData['repeatByDay'];
					}
					else {
						$repeatWeeklyByDay = [$dateTime->format('w')];
					}
				}
				
				if ($repeatType == 'monthly') {
					if (!empty($oldEventDateData['repeatByMonthDay'])) {
						$repeatType = 'monthlyByDayOfMonth';
						$repeatMonthlyByMonthDay = reset($oldEventDateData['repeatByMonthDay']);
					}
					else {
						$repeatType = 'monthlyByDayOfWeek';
						if (!empty($oldEventDateData['repeatByDay'])) {
							$repeatMonthlyByWeekDay = reset($oldEventDateData['repeatByDay']);
						}
						else {
							$repeatMonthlyByWeekDay = $dateTime->format('w');
						}
						if (!empty($oldEventDateData['repeatByWeek'])) {
							$repeatMonthlyDayOffset = reset($oldEventDateData['repeatByWeek']);
						}
					}
					
				}
				if ($repeatType == 'yearly') {
					if (!empty($oldEventDateData['repeatByMonthDay'])) {
						$repeatType = 'yearlyByDayOfMonth';
						$repeatYearlyByMonthDay = reset($oldEventDateData['repeatByMonthDay']);
					}
					else {
						$repeatType = 'yearlyByDayOfWeek';
						if (!empty($oldEventDateData['repeatByDay'])) {
							$repeatYearlyByWeekDay = reset($oldEventDateData['repeatByDay']);
						}
						else {
							$repeatYearlyByWeekDay = $dateTime->format('w');
						}
						if (!empty($oldEventDateData['repeatByWeek'])) {
							$repeatYearlyDayOffset = reset($oldEventDateData['repeatByWeek']);
						}
					}
					if (!empty($oldEventDateData['repeatByMonth'])) {
						$repeatYearlyByMonth = reset($oldEventDateData['repeatByMonth']);
					}
					else {
						$repeatYearlyByMonth = $dateTime->format('n');
					}
				}
			}
			
			$repeatEndCount = 1000;
			if (isset($oldEventDateData['repeatEndCount']) && $oldEventDateData['repeatEndCount'] < $repeatEndCount) $repeatEndCount = $oldEventDateData['repeatEndCount'];
			$repeatEndDate = 1395415497;
			if (isset($oldEventDateData['repeatEndTime']) && $oldEventDateData['repeatEndTime'] < $repeatEndDate) $repeatEndDate = $oldEventDateData['repeatEndTime'];
			$eventDateData = [
				'startTime' => $oldEventDateData['startTime'],
				'endTime' => $oldEventDateData['endTime'],
				'isFullDay' => $oldEventDateData['isFullDay'],
				'timezone' => 'UTC',
				'firstDayOfWeek' => isset($oldEventDateData['wkst']) ? $oldEventDateData['wkst'] : 1,
				'repeatType' => $repeatType,
				'repeatInterval' => isset($oldEventDateData['repeatInterval']) ? $oldEventDateData['repeatInterval'] : 1,
				'repeatWeeklyByDay' => $repeatWeeklyByDay,
				'repeatMonthlyByMonthDay' => $repeatMonthlyByMonthDay,
				'repeatMonthlyDayOffset' => $repeatMonthlyDayOffset,
				'repeatMonthlyByWeekDay' => $repeatMonthlyByWeekDay,
				'repeatYearlyByMonthDay' => $repeatYearlyByMonthDay,
				'repeatYearlyByMonth' => $repeatYearlyByMonth,
				'repeatYearlyDayOffset' => $repeatYearlyDayOffset,
				'repeatYearlyByWeekDay' => $repeatYearlyByWeekDay,
				'repeatEndType' => $repeatEndType,
				'repeatEndCount' => $repeatEndCount,
				'repeatEndDate' => $repeatEndDate
			];
			
			$data = [
				'userID' => $row['userID'] ?: null,
				'username' => $row['username'],
				'location' => $row['location'],
				'enableComments' => $row['enableComments'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'ipAddress' => $row['ipAddress'],
				'attachments' => $row['attachments'],
				'enableHtml' => $row['enableHtml'],
				'eventDate' => serialize($eventDateData)
			];
			if ($row['participationID']) {
				$data['enableParticipation'] = 1;
				$data['participationEndTime'] = $row['endTime'];
				$data['maxParticipants'] = $row['maxParticipants'];
				$data['participationIsChangeable'] = $row['isChangeable'];
				$data['participationIsPublic'] = $row['isPublic'];
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event')->import($row['eventID'], $data, ['categories' => [$row['calendarID']]]);
		}
	}
	
	/**
	 * Counts calendar attachments.
	 */
	public function countCalendarAttachments() {
		return $this->countAttachments('event');
	}
	
	/**
	 * Exports calendar attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarAttachments($offset, $limit) {
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1') {
			$sql = "SELECT		attachment.*, (SELECT eventID FROM wcf".$this->dbNo."_calendar_event WHERE messageID = attachment.containerID) AS eventID
				FROM		wcf".$this->dbNo."_attachment attachment
				WHERE		containerType = ?
						AND containerID > ?
				ORDER BY	attachmentID DESC";
		}
		else {
			$sql = "SELECT		attachment.*, (SELECT eventID FROM wcf".$this->dbNo."_calendar_event WHERE messageID = attachment.messageID) AS eventID
				FROM		wcf".$this->dbNo."_attachment attachment
				WHERE		messageType = ?
						AND messageID > ?
				ORDER BY	attachmentID DESC";
		}
		
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['event', 0]);
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'attachments/attachment-'.$row['attachmentID'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event.attachment')->import($row['attachmentID'], [
				'objectID' => $row['eventID'],
				'userID' => $row['userID'] ?: null,
				'filename' => $row['attachmentName'],
				'filesize' => $row['attachmentSize'],
				'fileType' => $row['fileType'],
				'isImage' => $row['isImage'],
				'downloads' => $row['downloads'],
				'lastDownloadTime' => $row['lastDownloadTime'],
				'uploadTime' => $row['uploadTime'],
				'showOrder' => !empty($row['showOrder']) ? $row['showOrder'] : 0
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Counts calendar event dates.
	 */
	public function countCalendarEventDates() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_calendar_event_date";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports calendar event dates.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDates($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_calendar_event_date
			ORDER BY	eventID, startTime";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event.date')->import($row['eventID'] . '-' . $row['startTime'], [
				'eventID' => $row['eventID'],
				'startTime' => $row['startTime'],
				'endTime' => $row['endTime'],
				'isFullDay' => $row['isFullDay']
			]);
		}
	}
	
	/**
	 * Counts calendar event date comments.
	 */
	public function countCalendarEventDateComments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_calendar_event_message
			WHERE	messageID NOT IN (SELECT messageID FROM wcf".$this->dbNo."_calendar_event)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports calendar event date comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDateComments($offset, $limit) {
		$sql = "SELECT		startTime
			FROM		wcf".$this->dbNo."_calendar_event_date
			WHERE		eventID = ?
			ORDER BY	startTime";
		$firstEventDateStatement = $this->database->prepareStatement($sql, 1);
		
		$sql = "SELECT		event_message.*
			FROM		wcf".$this->dbNo."_calendar_event_message event_message
			WHERE		event_message.messageID NOT IN (SELECT messageID FROM wcf".$this->dbNo."_calendar_event)
			ORDER BY	event_message.messageID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			// get first event date
			$firstEventDateStatement->execute([$row['eventID']]);
			$startTime = $firstEventDateStatement->fetchColumn();
			
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event.date.comment')->import($row['messageID'], [
				'objectID' => $row['eventID'] . '-' . $startTime,
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts calendar event date participations.
	 */
	public function countCalendarEventDateParticipation() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_calendar_event_participation_to_user";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports calendar event date participations.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDateParticipation($offset, $limit) {
		$sql = "SELECT		startTime
			FROM		wcf".$this->dbNo."_calendar_event_date
			WHERE		eventID = ?
			ORDER BY	startTime";
		$firstEventDateStatement = $this->database->prepareStatement($sql, 1);
		
		$sql = "SELECT		participation_to_user.*, participation.eventID
			FROM		wcf".$this->dbNo."_calendar_event_participation_to_user participation_to_user
			LEFT JOIN	wcf".$this->dbNo."_calendar_event_participation participation
			ON		(participation.participationID = participation_to_user.participationID)
			ORDER BY	participation_to_user.participationID, participation_to_user.userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			// get first event date
			$firstEventDateStatement->execute([$row['eventID']]);
			$startTime = $firstEventDateStatement->fetchColumn();
			if (!$startTime) continue;
				
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event.date.participation')->import(0, [
				'eventDateID' => $row['eventID'] . '-' . $startTime,
				'userID' => $row['userID'],
				'username' => $row['username'],
				'decision' => $row['decision'],
				'decisionTime' => $row['decisionTime']
			]);
		}
	}
	
	/**
	 * Returns the number of attachments.
	 * 
	 * @param	string		$type
	 * @return	integer
	 */
	private function countAttachments($type) {
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1') {
			$sql = "SELECT	COUNT(*) AS count
				FROM	wcf".$this->dbNo."_attachment
				WHERE	containerType = ?
					AND containerID > ?";
		}
		else {
			$sql = "SELECT	COUNT(*) AS count
				FROM	wcf".$this->dbNo."_attachment
				WHERE	messageType = ?
					AND messageID > ?";
		}
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$type, 0]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports attachments.
	 * 
	 * @param	string		$type
	 * @param	string		$objectType
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportAttachments($type, $objectType, $offset, $limit) {
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1') {
			$sql = "SELECT		*
				FROM		wcf".$this->dbNo."_attachment
				WHERE		containerType = ?
						AND containerID > ?
				ORDER BY	attachmentID DESC";
		}
		else {
			$sql = "SELECT		*
				FROM		wcf".$this->dbNo."_attachment
				WHERE		messageType = ?
						AND messageID > ?
				ORDER BY	attachmentID DESC";
		}
		
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$type, 0]);
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'attachments/attachment-'.$row['attachmentID'];
			
			ImportHandler::getInstance()->getImporter($objectType)->import($row['attachmentID'], [
				'objectID' => !empty($row['containerID']) ? $row['containerID'] : $row['messageID'],
				'userID' => $row['userID'] ?: null,
				'filename' => $row['attachmentName'],
				'filesize' => $row['attachmentSize'],
				'fileType' => $row['fileType'],
				'isImage' => $row['isImage'],
				'downloads' => $row['downloads'],
				'lastDownloadTime' => $row['lastDownloadTime'],
				'uploadTime' => $row['uploadTime'],
				'showOrder' => !empty($row['showOrder']) ? $row['showOrder'] : 0
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Returns all existing WCF 2.0 user options.
	 * 
	 * @return	array
	 */
	private function getExistingUserOptions() {
		$optionsNames = [];
		$sql = "SELECT	optionName
			FROM	wcf".WCF_N."_user_option
			WHERE	optionName NOT LIKE ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(['option%']);
		while ($row = $statement->fetchArray()) {
			$optionsNames[] = $row['optionName'];
		}
		
		return $optionsNames;
	}
	
	/**
	 * Returns the version of a package in the imported system or `false` if the package is
	 * not installed in the imported system.
	 * 
	 * @param	string		$name
	 * @return	string|boolean
	 */
	private function getPackageVersion($name) {
		$sql = "SELECT	packageVersion
			FROM	wcf".$this->dbNo."_package
			WHERE	package = ?";
		$statement = $this->database->prepareStatement($sql, 1);
		$statement->execute([$name]);
		$row = $statement->fetchArray();
		if ($row !== false) return $row['packageVersion'];
		
		return false;
	}
	
	/**
	 * Returns tags to import.
	 * 
	 * @param	string		$name
	 * @param	integer[]	$objectIDs
	 * @return	string[][]
	 */
	private function getTags($name, array $objectIDs) {
		$tags = [];
		if (substr($this->getPackageVersion('com.woltlab.wcf'), 0, 3) == '1.1' && $this->getPackageVersion('com.woltlab.wcf.tagging')) {
			// get taggable id
			$sql = "SELECT		taggableID
				FROM		wcf".$this->dbNo."_tag_taggable
				WHERE		name = ?
				ORDER BY	packageID";
			$statement = $this->database->prepareStatement($sql, 1);
			$statement->execute([$name]);
			$taggableID = $statement->fetchColumn();
			if ($taggableID) {
				$conditionBuilder = new PreparedStatementConditionBuilder();
				$conditionBuilder->add('tag_to_object.taggableID = ?', [$taggableID]);
				$conditionBuilder->add('tag_to_object.objectID IN (?)', [$objectIDs]);
				
				$sql = "SELECT		tag.name, tag_to_object.objectID
					FROM		wcf".$this->dbNo."_tag_to_object tag_to_object
					LEFT JOIN	wcf".$this->dbNo."_tag tag
					ON		(tag.tagID = tag_to_object.tagID)
					".$conditionBuilder;
				$statement = $this->database->prepareStatement($sql);
				$statement->execute($conditionBuilder->getParameters());
				while ($row = $statement->fetchArray()) {
					if (!isset($tags[$row['objectID']])) $tags[$row['objectID']] = [];
					$tags[$row['objectID']][] = $row['name'];
				}
			}
		}
		
		return $tags;
	}
	
	/**
	 * Returns message with fixed BBCodes as used in WCF.
	 *
	 * @param	string		$message
	 * @return	string
	 */
	private static function fixBBCodes($message) {
		// code bbcodes
		$message = preg_replace('~\[(php|java|css|html|xml|tpl|js|c)\]~', '[code=\\1]', $message);
		$message = preg_replace('~\[(php|java|css|html|xml|tpl|js|c)=(\d+)\]~', '[code=\\1,\\2]', $message);
		$message = str_replace('[mysql]', '[code=sql]', $message);
		$message = preg_replace('~\[mysql=(\d+)\]~', '[code=sql,\\1]', $message);
		$message = preg_replace('~\[/(?:php|java|css|html|xml|tpl|js|c|mysql)\]~', '[/code]', $message);
		
		// media bbcodes
		$message = preg_replace("~\[(?:youtube|myvideo|myspace|googlevideo|clipfish|sevenload)(?:='?([^'\],]+)'?)?(?:,[^\]]+)?\]~", '[media]\\1', $message);
		$message = preg_replace('~\[/(?:youtube|myvideo|myspace|googlevideo|clipfish|sevenload)\]~', '[/media]', $message);
		
		// remove crap
		$message = MessageUtil::stripCrap($message);
		
		return $message;
	}
}
