<?php
namespace wcf\system\exporter;
use blog\system\BLOGCore;
use gallery\system\GALLERYCore;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\package\Package;
use wcf\data\package\PackageCache;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\language\LanguageFactory;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\StringUtil;

/**
 * Exporter for Burning Board 4.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2017 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class WBB4xExporter extends AbstractExporter {
	/**
	 * wcf installation number
	 * @var	integer
	 */
	protected $dbNo = 0;
	
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
		'com.woltlab.wcf.user.comment' => 'ProfileComments',
		'com.woltlab.wcf.user.comment.response' => 'ProfileCommentResponses',
		'com.woltlab.wcf.user.avatar' => 'UserAvatars',
		'com.woltlab.wcf.user.option' => 'UserOptions',
		'com.woltlab.wcf.conversation.label' => 'ConversationLabels',
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
		'com.woltlab.wbb.like' => 'PostLikes',
		'com.woltlab.wcf.label' => 'Labels',
		'com.woltlab.wbb.acl' => 'ACLs',
		'com.woltlab.wcf.smiley.category' => 'SmileyCategories',
		'com.woltlab.wcf.smiley' => 'Smilies',
		
		'com.woltlab.blog.blog' => 'Blogs',
		'com.woltlab.blog.category' => 'BlogCategories',
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.comment.response' => 'BlogCommentResponses',
		'com.woltlab.blog.entry.like' => 'BlogEntryLikes',
		
		'com.woltlab.gallery.category' => 'GalleryCategories',
		'com.woltlab.gallery.album' => 'GalleryAlbums',
		'com.woltlab.gallery.image' => 'GalleryImages',
		'com.woltlab.gallery.image.comment' => 'GalleryComments',
		'com.woltlab.gallery.image.comment.response' => 'GalleryCommentResponses',
		'com.woltlab.gallery.image.like' => 'GalleryImageLikes',
		'com.woltlab.gallery.image.marker' => 'GalleryImageMarkers',
		
		'com.woltlab.calendar.category' => 'CalendarCategories',
		'com.woltlab.calendar.event' => 'CalendarEvents',
		'com.woltlab.calendar.event.attachment' => 'CalendarAttachments',
		'com.woltlab.calendar.event.date' => 'CalendarEventDates',
		'com.woltlab.calendar.event.date.comment' => 'CalendarEventDateComments',
		'com.woltlab.calendar.event.date.comment.response' => 'CalendarEventDateCommentResponses',
		'com.woltlab.calendar.event.date.participation' => 'CalendarEventDateParticipation',
		'com.woltlab.calendar.event.like' => 'CalendarEventLikes'
	];
	
	/**
	 * @inheritDoc
	 */
	protected $limits = [
		'com.woltlab.wcf.user' => 100,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.conversation.attachment' => 100,
		'com.woltlab.wbb.thread' => 200,
		'com.woltlab.wbb.attachment' => 100,
		'com.woltlab.wbb.acl' => 50
	];
	
	/**
	 * @inheritDoc
	 */
	public function init() {
		parent::init();
		
		if (preg_match('/^wcf(\d+)_$/', $this->databasePrefix, $match)) {
			$this->dbNo = $match[1];
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
		$supportedData = [
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
			'com.woltlab.calendar.event' => [
				'com.woltlab.calendar.category',
				'com.woltlab.calendar.event.attachment',
				'com.woltlab.calendar.event.date.comment',
				'com.woltlab.calendar.event.date.participation',
				'com.woltlab.calendar.event.like'
			],
			'com.woltlab.wcf.smiley' => [],
		];
		
		$gallery = PackageCache::getInstance()->getPackageByIdentifier('com.woltlab.gallery');
		if ($gallery && Package::compareVersion('2.1.0 Alpha 1', $gallery->packageVersion) != 1) {
			$supportedData['com.woltlab.gallery.image'] = [
				'com.woltlab.gallery.category',
				'com.woltlab.gallery.album',
				'com.woltlab.gallery.image.comment',
				'com.woltlab.gallery.image.like',
				'com.woltlab.gallery.image.marker'
			];
		}
		
		return $supportedData;
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM wcf".$this->dbNo."_user";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
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
			
			if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.comment';
				$queue[] = 'com.woltlab.wcf.user.comment.response';
			}
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if ($this->getPackageVersion('com.woltlab.wcf.conversation')) {
				if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
					if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
					
					$queue[] = 'com.woltlab.wcf.conversation';
					$queue[] = 'com.woltlab.wcf.conversation.message';
					$queue[] = 'com.woltlab.wcf.conversation.user';
					
					if (in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.attachment';
				}
			}
		}
		
		// board
		if ($this->getPackageVersion('com.woltlab.wbb')) {
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
		}
		
		// smiley
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.smiley.category';
			$queue[] = 'com.woltlab.wcf.smiley';
		}
		
		// blog
		if ($this->getPackageVersion('com.woltlab.blog')) {
			if (in_array('com.woltlab.blog.entry', $this->selectedData)) {
				$queue[] = 'com.woltlab.blog.blog';
				if (in_array('com.woltlab.blog.category', $this->selectedData)) $queue[] = 'com.woltlab.blog.category';
				$queue[] = 'com.woltlab.blog.entry';
				if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.attachment';
				if (in_array('com.woltlab.blog.entry.comment', $this->selectedData)) {
					$queue[] = 'com.woltlab.blog.entry.comment';
					$queue[] = 'com.woltlab.blog.entry.comment.response';
				}
				if (in_array('com.woltlab.blog.entry.like', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.like';
			}
		}
		
		// gallery
		if ($this->getPackageVersion('com.woltlab.gallery')) {
			if (in_array('com.woltlab.gallery.image', $this->selectedData)) {
				if (in_array('com.woltlab.gallery.category', $this->selectedData)) $queue[] = 'com.woltlab.gallery.category';
				if (in_array('com.woltlab.gallery.album', $this->selectedData)) $queue[] = 'com.woltlab.gallery.album';
				$queue[] = 'com.woltlab.gallery.image';
				if (in_array('com.woltlab.gallery.image.comment', $this->selectedData)) {
					$queue[] = 'com.woltlab.gallery.image.comment';
					$queue[] = 'com.woltlab.gallery.image.comment.response';
				}
				if (in_array('com.woltlab.gallery.image.like', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.like';
				if (in_array('com.woltlab.gallery.image.marker', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.marker';
			}
		}
		
		// calendar
		if ($this->getPackageVersion('com.woltlab.calendar')) {
			if (in_array('com.woltlab.calendar.event', $this->selectedData)) {
				if (in_array('com.woltlab.calendar.category', $this->selectedData)) $queue[] = 'com.woltlab.calendar.category';
				$queue[] = 'com.woltlab.calendar.event';
				$queue[] = 'com.woltlab.calendar.event.date';
				if (in_array('com.woltlab.calendar.event.attachment', $this->selectedData)) $queue[] = 'com.woltlab.calendar.event.attachment';
				if (in_array('com.woltlab.calendar.event.date.comment', $this->selectedData)) {
					$queue[] = 'com.woltlab.calendar.event.date.comment';
					$queue[] = 'com.woltlab.calendar.event.date.comment.response';
				}
				if (in_array('com.woltlab.calendar.event.like', $this->selectedData)) $queue[] = 'com.woltlab.calendar.event.like';
				if (in_array('com.woltlab.calendar.event.date.participation', $this->selectedData)) $queue[] = 'com.woltlab.calendar.event.date.participation';
			}
		}
		
		return $queue;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDefaultDatabasePrefix() {
		return 'wcf1_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_group";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user groups.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_group
			ORDER BY	groupID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		$groups = [];
		$i18nValues = [];
		while ($row = $statement->fetchArray()) {
			$groups[$row['groupID']] = [
				'groupName' => $row['groupName'],
				'groupDescription' => $row['groupDescription'],
				'groupType' => $row['groupType'],
				'priority' => $row['priority'],
				'userOnlineMarking' => !empty($row['userOnlineMarking']) ? $row['userOnlineMarking'] : '',
				'showOnTeamPage' => !empty($row['showOnTeamPage']) ? $row['showOnTeamPage'] : 0
			];
			
			if (strpos($row['groupName'], 'wcf.acp.group.group') === 0) {
				$i18nValues[] = $row['groupName'];
			}
			if (strpos($row['groupDescription'], 'wcf.acp.group.groupDescription') === 0) {
				$i18nValues[] = $row['groupDescription'];
			}
		}
		
		$i18nValues = $this->getI18nValues($i18nValues);
		
		foreach ($groups as $groupID => $groupData) {
			$i18nData = [];
			if (isset($i18nValues[$groupData['groupName']])) $i18nData['groupName'] = $i18nValues[$groupData['groupName']];
			if (isset($i18nValues[$groupData['groupDescription']])) $i18nData['groupName'] = $i18nValues[$groupData['groupDescription']];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($groupID, $groupData, ['i18n' => $i18nData]);
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
		
		// get users
		$sql = "SELECT		user_option_value.*, user_table.*,
					(
						SELECT	GROUP_CONCAT(groupID)
						FROM	wcf".$this->dbNo."_user_to_group
						WHERE	userID = user_table.userID
					) AS groupIDs,
					(
						SELECT		GROUP_CONCAT(language.languageCode)
						FROM		wcf".$this->dbNo."_user_to_language user_to_language
						LEFT JOIN	wcf".$this->dbNo."_language language
						ON		(language.languageID = user_to_language.languageID)
						WHERE		user_to_language.userID = user_table.userID
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
				'registrationIpAddress' => $row['registrationIpAddress'],
				'disableAvatar' => $row['disableAvatar'],
				'disableAvatarReason' => $row['disableAvatarReason'],
				'enableGravatar' => $row['enableGravatar'],
				'signature' => $row['signature'],
				'signatureEnableHtml' => $row['signatureEnableHtml'],
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
				if (isset($row['userOption'.$optionID])) {
					$additionalData['options'][$optionName] = $row['userOption'.$optionID];
				}
			}
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userID'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute([$row['password'], $newUserID]);
			}
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_rank";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
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
			ORDER BY	rankID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['rankID'], [
				'groupID' => $row['groupID'],
				'requiredPoints' => $row['requiredPoints'],
				'rankTitle' => $row['rankTitle'],
				'cssClassName' => $row['cssClassName'],
				'rankImage' => $row['rankImage'],
				'repeatImage' => $row['repeatImage'],
				'requiredGender' => $row['requiredGender']
			]);
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_follow", 'followID');
	}
	
	/**
	 * Exports followers.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_follow
			WHERE		followID BETWEEN ? AND ?
			ORDER BY	followID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, [
				'userID' => $row['userID'],
				'followUserID' => $row['followUserID'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts profile comments.
	 */
	public function countProfileComments() {
		return $this->countComments('com.woltlab.wcf.user.profileComment');
	}
	
	/**
	 * Exports profile comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportProfileComments($offset, $limit) {
		$this->exportComments('com.woltlab.wcf.user.profileComment', 'com.woltlab.wcf.user.comment', $offset, $limit);
	}
	
	/**
	 * Counts profile comment responses.
	 */
	public function countProfileCommentResponses() {
		return $this->countCommentResponses('com.woltlab.wcf.user.profileComment');
	}
	
	/**
	 * Exports profile comment responses.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportProfileCommentResponses($offset, $limit) {
		$this->exportCommentResponses('com.woltlab.wcf.user.profileComment', 'com.woltlab.wcf.user.comment.response', $offset, $limit);
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		return $this->__getMaxID("wcf".$this->dbNo."_user_avatar", 'avatarID');
	}
	
	/**
	 * Exports user avatars.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_user_avatar
			WHERE		avatarID BETWEEN ? AND ?
			ORDER BY	avatarID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['avatarID'], [
				'avatarName' => $row['avatarName'],
				'avatarExtension' => $row['avatarExtension'],
				'width' => $row['width'],
				'height' => $row['height'],
				'userID' => $row['userID'],
				'fileHash' => $row['fileHash']
			], ['fileLocation' => $this->fileSystemPath . 'images/avatars/' . substr($row['fileHash'], 0, 2) . '/' . $row['avatarID'] . '-' . $row['fileHash'] . '.' . $row['avatarExtension']]);
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
			$optionType = StringUtil::firstCharToUpperCase($row['optionType']);
			$className = 'wcf\system\option\\'.$optionType.'OptionType';
			if (!class_exists($className)) {
				$optionType = 'textarea';
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['optionID'], [
				'categoryName' => $row['categoryName'],
				'optionType' => $optionType,
				'defaultValue' => $row['defaultValue'],
				'validationPattern' => $row['validationPattern'],
				'selectOptions' => $row['selectOptions'],
				'required' => $row['required'],
				'askDuringRegistration' => $row['askDuringRegistration'],
				'searchable' => $row['searchable'],
				'isDisabled' => $row['isDisabled'],
				'editable' => $row['editable'],
				'visible' => $row['visible'],
				'showOrder' => $row['showOrder']
			], ['name' => $row['name'] ?: $row['optionName']]);
		}
	}
	
	/**
	 * Counts conversation labels.
	 */
	public function countConversationLabels() {
		return $this->__getMaxID("wcf".$this->dbNo."_conversation_label", 'labelID');
	}
	
	/**
	 * Exports conversation labels.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationLabels($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_conversation_label
			WHERE		labelID BETWEEN ? AND ?
			ORDER BY	labelID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['labelID'], [
				'userID' => $row['userID'],
				'label' => $row['label'],
				'cssClassName' => $row['cssClassName']
			]);
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		return $this->__getMaxID("wcf".$this->dbNo."_conversation", 'conversationID');
	}
	
	/**
	 * Exports conversations.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_conversation
			WHERE		conversationID BETWEEN ? AND ?
			ORDER BY	conversationID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['conversationID'], [
				'subject' => $row['subject'],
				'time' => $row['time'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'participantCanInvite' => $row['participantCanInvite'],
				'isClosed' => $row['isClosed'],
				'isDraft' => $row['isDraft'],
				'draftData' => $row['draftData']
			]);
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		return $this->__getMaxID("wcf".$this->dbNo."_conversation_message", 'messageID');
	}
	
	/**
	 * Exports conversation messages.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_conversation_message
			WHERE		messageID BETWEEN ? AND ?
			ORDER BY	messageID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['messageID'], [
				'conversationID' => $row['conversationID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['time'],
				'attachments' => $row['attachments'],
				'enableHtml' => $row['enableHtml'],
				'ipAddress' => $row['ipAddress']
			]);
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_conversation_to_user";
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
		$conversationIDs = $userID = $rows = [];
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_conversation_to_user
			ORDER BY	conversationID, participantID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$rows[] = $row;
			$conversationIDs[] = $row['conversationID'];
			$userIDs[] = $row['participantID'];
		}
		
		// get labels
		$labels = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('label.labelID = label_to_object.labelID');
		$conditionBuilder->add('label_to_object.conversationID IN (?)', [$conversationIDs]);
		$conditionBuilder->add('label.userID IN (?)', [$userIDs]);
		
		$sql = "SELECT		label_to_object.conversationID, label.userID, label.labelID
			FROM		wcf".$this->dbNo."_conversation_label_to_object label_to_object,
					wcf".$this->dbNo."_conversation_label label
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$labels[$row['conversationID']][$row['userID']][] = $row['labelID'];
		}
		
		foreach ($rows as $row) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, [
				'conversationID' => $row['conversationID'],
				'participantID' => $row['participantID'],
				'username' => $row['username'],
				'hideConversation' => $row['hideConversation'],
				'isInvisible' => $row['isInvisible'],
				'lastVisitTime' => $row['lastVisitTime']
			], ['labelIDs' => isset($labels[$row['conversationID']][$row['participantID']]) ? $labels[$row['conversationID']][$row['participantID']] : []]);
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		return $this->countAttachments('com.woltlab.wcf.conversation.attachment');
	}
	
	/**
	 * Exports conversation attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationAttachments($offset, $limit) {
		$this->exportAttachments('com.woltlab.wcf.conversation.message', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wbb".$this->dbNo."_board";
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
		$sql = "SELECT		*
			FROM		wbb".$this->dbNo."_board
			ORDER BY	parentID, position";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$i18nValues = [];
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parentID']][] = $row;
			
			if (strpos($row['title'], 'wbb.board.board') === 0) {
				$i18nValues[] = $row['title'];
			}
			if (strpos($row['description'], 'wbb.board.board') === 0) {
				$i18nValues[] = $row['description'];
			}
		}
		
		$i18nValues = $this->getI18nValues($i18nValues);
		if (!empty($i18nValues)) {
			foreach ($this->boardCache as &$boards) {
				foreach ($boards as &$board) {
					$board['i18n'] = [];
					if (isset($i18nValues[$board['title']])) $board['i18n']['title'] = $i18nValues[$board['title']];
					if (isset($i18nValues[$board['description']])) $board['i18n']['description'] = $i18nValues[$board['description']];
				}
				unset($board);
			}
			unset($boards);
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 *
	 * @param	integer		$parentID
	 */
	protected function exportBoardsRecursively($parentID = null) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['boardID'], [
				'parentID' => $board['parentID'],
				'position' => $board['position'],
				'boardType' => $board['boardType'],
				'title' => $board['title'],
				'description' => $board['description'],
				'descriptionUseHtml' => $board['descriptionUseHtml'],
				'externalURL' => $board['externalURL'],
				'time' => $board['time'],
				'countUserPosts' => $board['countUserPosts'],
				'daysPrune' => $board['daysPrune'],
				'enableMarkingAsDone' => $board['enableMarkingAsDone'],
				'ignorable' => $board['ignorable'],
				'isClosed' => $board['isClosed'],
				'isInvisible' => $board['isInvisible'],
				'postSortOrder' => $board['postSortOrder'],
				'postsPerPage' => $board['postsPerPage'],
				'searchable' => $board['searchable'],
				'searchableForSimilarThreads' => $board['searchableForSimilarThreads'],
				'showSubBoards' => $board['showSubBoards'],
				'sortField' => $board['sortField'],
				'sortOrder' => $board['sortOrder'],
				'threadsPerPage' => $board['threadsPerPage'],
				'clicks' => $board['clicks'],
				'posts' => $board['posts'],
				'threads' => $board['threads']
			], [
				'i18n' => (isset($board['i18n']) ? $board['i18n'] : [])
			]);
			
			$this->exportBoardsRecursively($board['boardID']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID("wbb".$this->dbNo."_thread", 'threadID');
	}
	
	/**
	 * Exports threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportThreads($offset, $limit) {
		// get thread ids
		$threadIDs = $announcementIDs = [];
		$sql = "SELECT		threadID, isAnnouncement
			FROM		wbb".$this->dbNo."_thread
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
				FROM		wbb".$this->dbNo."_thread_announcement
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
		
		// get labels
		$labels = $this->getLabels('com.woltlab.wbb.thread', $threadIDs);
		
		// get threads
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('threadID IN (?)', [$threadIDs]);
		
		$sql = "SELECT		thread.*, language.languageCode
			FROM		wbb".$this->dbNo."_thread thread
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
				'movedThreadID' => $row['movedThreadID'],
				'movedTime' => $row['movedTime'],
				'isDone' => $row['isDone'],
				'deleteTime' => $row['deleteTime'],
				'lastPostTime' => $row['lastPostTime'],
				'hasLabels' => $row['hasLabels']
			];
			$additionalData = [];
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			if (!empty($assignedBoards[$row['threadID']])) $additionalData['assignedBoards'] = $assignedBoards[$row['threadID']];
			if (isset($labels[$row['threadID']])) $additionalData['labels'] = $labels[$row['threadID']];
			if (isset($tags[$row['threadID']])) $additionalData['tags'] = $tags[$row['threadID']];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['threadID'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID("wbb".$this->dbNo."_post", 'postID');
	}
	
	/**
	 * Exports posts.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		*
			FROM		wbb".$this->dbNo."_post
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
				'message' => $row['message'],
				'time' => $row['time'],
				'isDeleted' => $row['isDeleted'],
				'isDisabled' => $row['isDisabled'],
				'isClosed' => $row['isClosed'],
				'editorID' => $row['editorID'],
				'editor' => $row['editor'],
				'lastEditTime' => $row['lastEditTime'],
				'editCount' => $row['editCount'],
				'editReason' => $row['editReason'],
				'attachments' => $row['attachments'],
				'enableHtml' => $row['enableHtml'],
				'ipAddress' => $row['ipAddress'],
				'deleteTime' => $row['deleteTime']
			]);
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments('com.woltlab.wbb.post');
	}
	
	/**
	 * Exports post attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('com.woltlab.wbb.post', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_user_object_watch
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.user.objectWatch', 'com.woltlab.wbb.thread')]);
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
			FROM		wcf".$this->dbNo."_user_object_watch
			WHERE		objectTypeID = ?
			ORDER BY	watchID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.user.objectWatch', 'com.woltlab.wbb.thread')]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, [
				'objectID' => $row['objectID'],
				'userID' => $row['userID'],
				'notification' => $row['notification']
			]);
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_poll
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.poll', 'com.woltlab.wbb.post')]);
		$row = $statement->fetchArray();
		return $row['count'];
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
			WHERE		objectTypeID = ?
			ORDER BY	pollID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.poll', 'com.woltlab.wbb.post')]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollID'], [
				'objectID' => $row['objectID'],
				'question' => $row['question'],
				'time' => $row['time'],
				'endTime' => $row['endTime'],
				'isChangeable' => $row['isChangeable'],
				'isPublic' => $row['isPublic'],
				'sortByVotes' => $row['sortByVotes'],
				'resultsRequireVote' => $row['resultsRequireVote'],
				'maxVotes' => $row['maxVotes'],
				'votes' => $row['votes']
			]);
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_poll_option
			WHERE	pollID IN (
					SELECT	pollID
					FROM	wcf".$this->dbNo."_poll
					WHERE	objectTypeID = ?
				)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.poll', 'com.woltlab.wbb.post')]);
		$row = $statement->fetchArray();
		return $row['count'];
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
						WHERE	objectTypeID = ?
					)
			ORDER BY	optionID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.poll', 'com.woltlab.wbb.post')]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['optionID'], [
				'pollID' => $row['pollID'],
				'optionValue' => $row['optionValue'],
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
					WHERE	objectTypeID = ?
				)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.poll', 'com.woltlab.wbb.post')]);
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
						WHERE	objectTypeID = ?
					)
			ORDER BY	optionID, userID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.poll', 'com.woltlab.wbb.post')]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, [
				'pollID' => $row['pollID'],
				'optionID' => $row['optionID'],
				'userID' => $row['userID']
			]);
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countPostLikes() {
		return $this->countLikes('com.woltlab.wbb.likeablePost');
	}
	
	/**
	 * Exports likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPostLikes($offset, $limit) {
		$this->exportLikes('com.woltlab.wbb.likeablePost', 'com.woltlab.wbb.like', $offset, $limit);
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_label";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
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
		// get labels array($this->getObjectTypeID('com.woltlab.wcf.label.object', 'com.woltlab.wbb.thread'))
		$labels = [];
		$sql = "SELECT	*
			FROM	wcf".$this->dbNo."_label";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if (!isset($labels[$row['groupID']])) $labels[$row['groupID']] = [];
			$labels[$row['groupID']][] = $row;
		}
		
		// get label groups
		$labelGroups = [];
		$sql = "SELECT	*
			FROM	wcf".$this->dbNo."_label_group";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$labelGroups[] = $row;
		}
		
		// get board ids
		$boardIDs = [];
		$sql = "SELECT	*
			FROM	wcf".$this->dbNo."_label_group_to_object
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board')]);
		while ($row = $statement->fetchArray()) {
			if (!isset($boardIDs[$row['groupID']])) $boardIDs[$row['groupID']] = [];
			$boardIDs[$row['groupID']][] = $row['objectID'];
		}
		
		if (!empty($labelGroups)) {
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
				
			foreach ($labelGroups as $labelGroup) {
				// import label group
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($labelGroup['groupID'], [
					'groupName' => $labelGroup['groupName']
				], ['objects' => [$objectType->objectTypeID => !empty($boardIDs[$labelGroup['groupID']]) ? $boardIDs[$labelGroup['groupID']] : []]]);
				
				// import labels
				if (!empty($labels[$labelGroup['groupID']])) {
					foreach ($labels[$labelGroup['groupID']] as $label) {
						ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($label['labelID'], [
							'groupID' => $labelGroup['groupID'],
							'label' => $label['label'],
							'cssClassName' => $label['cssClassName']
						]);
					}
				}
			}
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	(SELECT COUNT(*) FROM wcf".$this->dbNo."_acl_option_to_group)
				+ (SELECT COUNT(*) FROM wcf".$this->dbNo."_acl_option_to_user) AS count";
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
		$objectTypeID = $this->getObjectTypeID('com.woltlab.wcf.acl', 'com.woltlab.wbb.board');
		
		$sql = "(
				SELECT		acl_option.optionName, acl_option.optionID,
						option_to_group.objectID, option_to_group.optionValue, 0 AS userID, option_to_group.groupID
				FROM		wcf".$this->dbNo."_acl_option_to_group option_to_group,
						wcf".$this->dbNo."_acl_option acl_option
				WHERE		acl_option.optionID = option_to_group.optionID
						AND acl_option.objectTypeID = ?
			)
			UNION
			(
				SELECT		acl_option.optionName, acl_option.optionID,
						option_to_user.objectID, option_to_user.optionValue, option_to_user.userID, 0 AS groupID
				FROM		wcf".$this->dbNo."_acl_option_to_user option_to_user,
						wcf".$this->dbNo."_acl_option acl_option
				WHERE		acl_option.optionID = option_to_user.optionID
						AND acl_option.objectTypeID = ?
			)
			ORDER BY	optionID, objectID, userID, groupID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$objectTypeID, $objectTypeID]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'objectID' => $row['objectID'],
				'optionValue' => $row['optionValue']
			];
			if ($row['userID']) $data['userID'] = $row['userID'];
			if ($row['groupID']) $data['groupID'] = $row['groupID'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, $data, ['optionName' => $row['optionName']]);
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
				'categoryID' => $row['categoryID'],
				'smileyTitle' => $row['smileyTitle'],
				'smileyCode' => $row['smileyCode'],
				'aliases' => $row['aliases'],
				'showOrder' => $row['showOrder']
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Counts smiley categories.
	 */
	public function countSmileyCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_category
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.category', 'com.woltlab.wcf.bbcode.smiley')]);
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
		$this->exportCategories('com.woltlab.wcf.bbcode.smiley', 'com.woltlab.wcf.smiley.category', $offset, $limit);
		
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_category
			WHERE		objectTypeID = ?
			ORDER BY	categoryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.category', 'com.woltlab.wcf.bbcode.smiley')]);
		$categories = $i18nValues = [];
		while ($row = $statement->fetchArray()) {
			$categories[$row['categoryID']] = [
				'title' => $row['title'],
				'description' => $row['description'],
				'parentCategoryID' => 0,
				'showOrder' => $row['showOrder'],
				'time' => $row['time'],
				'isDisabled' => $row['isDisabled']
			];
			
			if (strpos($row['title'], 'wcf.category') === 0) {
				$i18nValues[] = $row['title'];
			}
			if (strpos($row['description'], 'wcf.category') === 0) {
				$i18nValues[] = $row['description'];
			}
		}
		
		$i18nValues = $this->getI18nValues($i18nValues);
		
		foreach ($categories as $categoryID => $categoryData) {
			$i18nData = [];
			if (isset($i18nValues[$categoryData['title']])) $i18nData['title'] = $i18nValues[$categoryData['title']];
			if (isset($i18nValues[$categoryData['description']])) $i18nData['description'] = $i18nValues[$categoryData['description']];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley.category')->import($categoryID, $categoryData, ['i18n' => $i18nData]);
		}
	}
	
	/**
	 * Counts blogs.
	 */
	public function countBlogs() {
		if (version_compare($this->getPackageVersion('com.woltlab.blog'), '2.1.0 Alpha 1', '>=')
			&& version_compare(BLOGCore::getInstance()->getPackage()->packageVersion, '2.1.0 Alpha 1', '>=')) {
			return $this->__getMaxID("blog".$this->dbNo."_blog", 'blogID');
		}
		
		// version 2.0 does not support blogs
		return 0;
	}
	
	/**
	 * Exports blogs.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogs($offset, $limit) {
		$sql = "SELECT		blog.*, language.languageCode
			FROM		blog".$this->dbNo."_blog blog
			LEFT JOIN	wcf".$this->dbNo."_language language
			ON		(language.languageID = blog.languageID)
			WHERE		blogID BETWEEN ? AND ?
			ORDER BY	blogID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$additionalData = [];
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.blog')->import($row['blogID'], [
				'userID' => $row['userID'],
				'username' => $row['username'],
				'title' => $row['title'],
				'description' => $row['description'],
				'accessLevel' => $row['accessLevel'],
				'isFeatured' => $row['isFeatured']
			], $additionalData);
		}
	}
	
	/**
	 * Counts blog categories.
	 */
	public function countBlogCategories() {
		return $this->countCategories('com.woltlab.blog.category');
	}
	
	/**
	 * Exports blog categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogCategories($offset, $limit) {
		$this->exportCategories('com.woltlab.blog.category', 'com.woltlab.blog.category', $offset, $limit);
	}
	
	/**
	 * Counts blog entries.
	 */
	public function countBlogEntries() {
		return $this->__getMaxID("blog".$this->dbNo."_entry", 'entryID');
	}
	
	/**
	 * Exports blog entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntries($offset, $limit) {
		$sourceVersion21 = version_compare($this->getPackageVersion('com.woltlab.blog'), '2.1.0 Alpha 1', '>=');
		$destVersion21 = version_compare(BLOGCore::getInstance()->getPackage()->packageVersion, '2.1.0 Alpha 1', '>=');
		
		// get entry ids
		$entryIDs = [];
		$sql = "SELECT		entryID
			FROM		blog".$this->dbNo."_entry
			WHERE		entryID BETWEEN ? AND ?
			ORDER BY	entryID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$entryIDs[] = $row['entryID'];
		}
		
		if (empty($entryIDs)) return;
		
		// get tags
		$tags = $this->getTags('com.woltlab.blog.entry', $entryIDs);
		
		// get categories
		$categories = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('entryID IN (?)', [$entryIDs]);
		
		$sql = "SELECT		* 
			FROM		blog".$this->dbNo."_entry_to_category
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['entryID']])) $categories[$row['entryID']] = [];
			$categories[$row['entryID']][] = $row['categoryID'];
		}
		
		// get entries
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('entry.entryID IN (?)', [$entryIDs]);
		
		$sql = "SELECT		entry.*, language.languageCode
			FROM		blog".$this->dbNo."_entry entry
			LEFT JOIN	wcf".$this->dbNo."_language language
			ON		(language.languageID = entry.languageID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$additionalData = [];
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			if (isset($tags[$row['entryID']])) $additionalData['tags'] = $tags[$row['entryID']];
			if (isset($categories[$row['entryID']])) $additionalData['categories'] = $categories[$row['entryID']];
			
			$data = [
				'userID' => $row['userID'],
				'username' => $row['username'],
				'subject' => $row['subject'],
				'message' => $row['message'],
				'time' => $row['time'],
				'attachments' => $row['attachments'],
				'comments' => $row['comments'],
				'views' => $row['views'],
				'enableHtml' => $row['enableHtml'],
				'enableComments' => $row['enableComments'],
				'isDisabled' => $row['isDisabled'],
				'isDeleted' => $row['isDeleted'],
				'isPublished' => $row['isPublished'],
				'publicationDate' => $row['publicationDate'],
				'ipAddress' => $row['ipAddress'],
				'deleteTime' => $row['deleteTime']
			];
			
			if ($sourceVersion21 && $destVersion21) {
				$data['blogID'] = $row['blogID'];
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['entryID'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts blog attachments.
	 */
	public function countBlogAttachments() {
		return $this->countAttachments('com.woltlab.blog.entry');
	}
	
	/**
	 * Exports blog attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogAttachments($offset, $limit) {
		$this->exportAttachments('com.woltlab.blog.entry', 'com.woltlab.blog.entry.attachment', $offset, $limit);
	}
	
	/**
	 * Counts blog comments.
	 */
	public function countBlogComments() {
		return $this->countComments('com.woltlab.blog.entryComment');
	}
	
	/**
	 * Exports blog comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogComments($offset, $limit) {
		$this->exportComments('com.woltlab.blog.entryComment', 'com.woltlab.blog.entry.comment', $offset, $limit);
	}
	
	/**
	 * Counts blog comment responses.
	 */
	public function countBlogCommentResponses() {
		return $this->countCommentResponses('com.woltlab.blog.entryComment');
	}
	
	/**
	 * Exports blog comment responses.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogCommentResponses($offset, $limit) {
		$this->exportCommentResponses('com.woltlab.blog.entryComment', 'com.woltlab.blog.entry.comment.response', $offset, $limit);
	}
	
	/**
	 * Counts blog entry likes.
	 */
	public function countBlogEntryLikes() {
		return $this->countLikes('com.woltlab.blog.likeableEntry');
	}
	
	/**
	 * Exports blog entry likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntryLikes($offset, $limit) {
		$this->exportLikes('com.woltlab.blog.likeableEntry', 'com.woltlab.blog.entry.like', $offset, $limit);
	}
	
	/**
	 * Counts gallery albums.
	 */
	public function countGalleryAlbums() {
		return $this->__getMaxID("gallery".$this->dbNo."_album", 'albumID');
	}
	
	/**
	 * Exports gallery albums.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryAlbums($offset, $limit) {
		$sourceVersion21 = version_compare($this->getPackageVersion('com.woltlab.gallery'), '2.1.0 Alpha 1', '>=');
		$destVersion21 = version_compare(GALLERYCore::getInstance()->getPackage()->packageVersion, '2.1.0 Alpha 1', '>=');
		
		$sql = "SELECT		*
			FROM		gallery".$this->dbNo."_album
			WHERE		albumID BETWEEN ? AND ?
			ORDER BY	albumID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'userID' => $row['userID'],
				'username' => $row['username'] ?: '',
				'title' => $row['title'],
				'description' => $row['description'],
				'lastUpdateTime' => $row['lastUpdateTime']
			];
			
			if ($sourceVersion21 && $destVersion21) {
				$data['accessLevel'] = $row['accessLevel'];
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.album')->import($row['albumID'], $data);
		}
	}
	
	/**
	 * Counts gallery categories.
	 */
	public function countGalleryCategories() {
		return $this->countCategories('com.woltlab.gallery.category');
	}
	
	/**
	 * Exports gallery categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryCategories($offset, $limit) {
		$this->exportCategories('com.woltlab.gallery.category', 'com.woltlab.gallery.category', $offset, $limit);
	}
	
	/**
	 * Counts gallery images.
	 */
	public function countGalleryImages() {
		return $this->__getMaxID("gallery".$this->dbNo."_image", 'imageID');
	}
	
	/**
	 * Exports gallery images.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryImages($offset, $limit) {
		$sourceVersion21 = version_compare($this->getPackageVersion('com.woltlab.gallery'), '2.1.0 Alpha 1', '>=');
		$destVersion21 = version_compare(GALLERYCore::getInstance()->getPackage()->packageVersion, '2.1.0 Alpha 1', '>=');
		
		// build path to gallery image directories
		$sql = "SELECT	packageDir
			FROM	wcf".$this->dbNo."_package
			WHERE	package = ?";
		$statement = $this->database->prepareStatement($sql, 1);
		$statement->execute(['com.woltlab.gallery']);
		$packageDir = $statement->fetchColumn();
		$imageFilePath = FileUtil::getRealPath($this->fileSystemPath.'/'.$packageDir);
		
		// fetch image data
		$sql = "SELECT		*
			FROM		gallery".$this->dbNo."_image
			WHERE		imageID BETWEEN ? AND ?
			ORDER BY	imageID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		
		$imageIDs = $images = [];
		while ($row = $statement->fetchArray()) {
			$imageIDs[] = $row['imageID'];
			
			$images[$row['imageID']] = [
				'tmpHash' => $row['tmpHash'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'albumID' => $row['albumID'],
				'title' => $row['title'],
				'description' => $row['description'],
				'filename' => $row['filename'],
				'fileExtension' => $row['fileExtension'],
				'fileHash' => $row['fileHash'],
				'filesize' => $row['filesize'],
				'comments' => $row['comments'],
				'views' => $row['views'],
				'cumulativeLikes' => $row['cumulativeLikes'],
				'uploadTime' => $row['uploadTime'],
				'width' => $row['width'],
				'creationTime' => $row['creationTime'],
				'height' => $row['height'],
				'orientation' => $row['orientation'],
				'camera' => $row['camera'],
				'location' => $row['location'],
				'latitude' => $row['latitude'],
				'longitude' => $row['longitude'],
				'thumbnailX' => $row['thumbnailX'],
				'thumbnailY' => $row['thumbnailY'],
				'thumbnailHeight' => $row['thumbnailHeight'],
				'thumbnailWidth' => $row['thumbnailWidth'],
				'tinyThumbnailSize' => $row['tinyThumbnailSize'],
				'smallThumbnailSize' => $row['smallThumbnailSize'],
				'mediumThumbnailSize' => $row['mediumThumbnailSize'],
				'largeThumbnailSize' => $row['largeThumbnailSize'],
				'ipAddress' => $row['ipAddress'],
				'enableComments' => $row['enableComments'],
				'isDisabled' => $row['isDisabled'],
				'isDeleted' => $row['isDeleted'],
				'deleteTime' => $row['deleteTime'],
				'exifData' => $row['exifData'],
			];
			
			if ($sourceVersion21 && $destVersion21) {
				$images[$row['imageID']] = array_merge($images[$row['imageID']], [
					'enableHtml' => $row['enableHtml'],
					'rawExifData' => $row['rawExifData'],
					'hasEmbeddedObjects' => $row['hasEmbeddedObjects'],
					'hasMarkers' => $row['hasMarkers'],
					'showOrder' => $row['showOrder'],
					'hasOriginalWatermark' => $row['hasOriginalWatermark']
				]);
			}
		}
		
		if (empty($imageIDs)) return;
		
		// fetch tags
		$tags = $this->getTags('com.woltlab.gallery.image', $imageIDs);
		
		// fetch categories
		$categories = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('imageID IN (?)', [$imageIDs]);
		
		$sql = "SELECT		*
			FROM		gallery".$this->dbNo."_image_to_category
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['imageID']])) {
				$categories[$row['imageID']] = [];
			}
			$categories[$row['imageID']][] = $row['categoryID'];
		}
		
		foreach ($images as $imageID => $imageData) {
			$additionalData = [
				'fileLocation' => $imageFilePath .'/userImages/' . substr($imageData['fileHash'], 0, 2) . '/' . $imageID . '-' . $imageData['fileHash'] . '.' . $imageData['fileExtension']
			];
			
			if (isset($categories[$imageID])) {
				$additionalData['categories'] = $categories[$imageID];
			}
			if (isset($tags[$imageID])) {
				$additionalData['tags'] = $tags[$imageID];
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image')->import($imageID, $imageData, $additionalData);
		}
	}
	
	/**
	 * Counts gallery image markers.
	 */
	public function countGalleryImageMarkers() {
		if (version_compare($this->getPackageVersion('com.woltlab.gallery'), '2.1.0 Alpha 1', '>=')
			&& version_compare(GALLERYCore::getInstance()->getPackage()->packageVersion, '2.1.0 Alpha 1', '>=')) {
			return $this->__getMaxID("gallery".$this->dbNo."_image_marker", 'markerID');
		}
		
		// version 2.0 does not support image markers
		return 0;
	}
	
	/**
	 * Exports gallery image markers.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryImageMarkers($offset, $limit) {
		$sql = "SELECT		*
			FROM		gallery".$this->dbNo."_image_marker
			WHERE		markerID BETWEEN ? AND ?
			ORDER BY	markerID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image.marker')->import($row['markerID'], [
				'imageID' => $row['imageID'],
				'positionX' => $row['positionX'],
				'positionY' => $row['positionY'],
				'userID' => $row['userID'],
				'description' => $row['description']
			]);
		}
	}
	
	/**
	 * Counts gallery comments.
	 */
	public function countGalleryComments() {
		return $this->countComments('com.woltlab.gallery.imageComment');
	}
	
	/**
	 * Exports gallery comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryComments($offset, $limit) {
		$this->exportComments('com.woltlab.gallery.imageComment', 'com.woltlab.gallery.image.comment', $offset, $limit);
	}
	
	/**
	 * Counts gallery comment responses.
	 */
	public function countGalleryCommentResponses() {
		return $this->countCommentResponses('com.woltlab.gallery.imageComment');
	}
	
	/**
	 * Exports gallery comment responses.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryCommentResponses($offset, $limit) {
		$this->exportCommentResponses('com.woltlab.gallery.imageComment', 'com.woltlab.gallery.image.comment.response', $offset, $limit);
	}
	
	/**
	 * Counts gallery image likes.
	 */
	public function countGalleryImageLikes() {
		return $this->countLikes('com.woltlab.gallery.likeableImage');
	}
	
	/**
	 * Exports gallery image likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryImageLikes($offset, $limit) {
		$this->exportLikes('com.woltlab.gallery.likeableImage', 'com.woltlab.gallery.image.like', $offset, $limit);
	}
	
	/**
	 * Counts calendar events.
	 */
	public function countCalendarEvents() {
		return $this->__getMaxID("calendar".$this->dbNo."_event", 'eventID');
	}
	
	/**
	 * Exports calendar events.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEvents($offset, $limit) {
		// get event ids
		$eventIDs = [];
		$sql = "SELECT		eventID
			FROM		calendar".$this->dbNo."_event
			WHERE		eventID BETWEEN ? AND ?
			ORDER BY	eventID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$eventIDs[] = $row['eventID'];
		}
		
		if (empty($eventIDs)) return;
		
		// get tags
		$tags = $this->getTags('com.woltlab.calendar.event', $eventIDs);
		
		// get categories
		$categories = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('eventID IN (?)', [$eventIDs]);
		
		$sql = "SELECT		*
			FROM		calendar".$this->dbNo."_event_to_category
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['eventID']])) $categories[$row['eventID']] = [];
			$categories[$row['eventID']][] = $row['categoryID'];
		}
		
		// get event
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('event.eventID IN (?)', [$eventIDs]);
		$sql = "SELECT		event.*, language.languageCode
			FROM		calendar".$this->dbNo."_event event
			LEFT JOIN	wcf".$this->dbNo."_language language
			ON		(language.languageID = event.languageID)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$additionalData = [];
			if ($row['languageCode']) $additionalData['languageCode'] = $row['languageCode'];
			if (isset($tags[$row['eventID']])) $additionalData['tags'] = $tags[$row['eventID']];
			if (isset($categories[$row['eventID']])) $additionalData['categories'] = $categories[$row['eventID']];
			
			$data = [
				'userID' => $row['userID'],
				'username' => $row['username'],
				'subject' => $row['subject'],
				'message' => $row['message'],
				'time' => $row['time'],
				'eventDate' => $row['eventDate'],
				'views' => $row['views'],
				'enableHtml' => $row['enableHtml'],
				'enableComments' => $row['enableComments'],
				'isDisabled' => $row['isDisabled'],
				'isDeleted' => $row['isDeleted'],
				'ipAddress' => $row['ipAddress'],
				'deleteTime' => $row['deleteTime'],
				'location' => $row['location'],
				'latitude' => $row['latitude'],
				'longitude' => $row['longitude'],
				'enableParticipation' => $row['enableParticipation'],
				'participationEndTime' => $row['participationEndTime'],
				'maxParticipants' => $row['maxParticipants'],
				'maxCompanions' => $row['maxCompanions'],
				'participationIsChangeable' => $row['participationIsChangeable'],
				'participationIsPublic' => $row['participationIsPublic']
			];
				
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event')->import($row['eventID'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts calendar event dates.
	 */
	public function countCalendarEventDates() {
		return $this->__getMaxID("calendar".$this->dbNo."_event_date", 'eventDateID');
	}
	
	/**
	 * Exports calendar event dates.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDates($offset, $limit) {
		$sql = "SELECT		*
			FROM		calendar".$this->dbNo."_event_date
			WHERE		eventDateID BETWEEN ? AND ?
			ORDER BY	eventDateID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event.date')->import($row['eventDateID'], [
				'eventID' => $row['eventID'],
				'startTime' => $row['startTime'],
				'endTime' => $row['endTime'],
				'isFullDay' => $row['isFullDay'],
				'participants' => $row['participants']
			]);
		}
	}
	
	/**
	 * Counts calendar event date participations.
	 */
	public function countCalendarEventDateParticipation() {
		return $this->__getMaxID("calendar".$this->dbNo."_event_date_participation", 'participationID');
	}
	
	/**
	 * Exports calendar event date participations.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDateParticipation($offset, $limit) {
		$sql = "SELECT		*
			FROM		calendar".$this->dbNo."_event_date_participation
			WHERE		participationID BETWEEN ? AND ?
			ORDER BY	participationID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event.date.participation')->import($row['participationID'], [
				'eventDateID' => $row['eventDateID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'decision' => $row['decision'],
				'decisionTime' => $row['decisionTime'],
				'participants' => $row['participants'],
				'message' => $row['message']
			]);
		}
	}
	
	/**
	 * Counts calendar categories.
	 */
	public function countCalendarCategories() {
		return $this->countCategories('com.woltlab.calendar.category');
	}
	
	/**
	 * Exports calendar categories.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarCategories($offset, $limit) {
		$this->exportCategories('com.woltlab.calendar.category', 'com.woltlab.calendar.category', $offset, $limit);
	}
	
	/**
	 * Counts calendar attachments.
	 */
	public function countCalendarAttachments() {
		return $this->countAttachments('com.woltlab.calendar.event');
	}
	
	/**
	 * Exports calendar attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarAttachments($offset, $limit) {
		$this->exportAttachments('com.woltlab.calendar.event', 'com.woltlab.calendar.event.attachment', $offset, $limit);
	}
	
	/**
	 * Counts calendar event comments.
	 */
	public function countCalendarEventDateComments() {
		return $this->countComments('com.woltlab.calendar.eventDateComment');
	}
	
	/**
	 * Exports calendar event comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDateComments($offset, $limit) {
		$this->exportComments('com.woltlab.calendar.eventDateComment', 'com.woltlab.calendar.event.date.comment', $offset, $limit);
	}
	
	/**
	 * Counts calendar event comment responses.
	 */
	public function countCalendarEventDateCommentResponses() {
		return $this->countCommentResponses('com.woltlab.calendar.eventDateComment');
	}
	
	/**
	 * Exports calendar event comment responses.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventDateCommentResponses($offset, $limit) {
		$this->exportCommentResponses('com.woltlab.calendar.eventDateComment', 'com.woltlab.calendar.event.date.comment.response', $offset, $limit);
	}
	
	/**
	 * Counts calendar event likes.
	 */
	public function countCalendarEventLikes() {
		return $this->countLikes('com.woltlab.calendar.likeableEvent');
	}
	
	/**
	 * Exports gallery image likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEventLikes($offset, $limit) {
		$this->exportLikes('com.woltlab.calendar.likeableEvent', 'com.woltlab.calendar.event.like', $offset, $limit);
	}
	
	/**
	 * Counts comments.
	 *
	 * @param	integer		$objectType
	 */
	private function countComments($objectType) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_comment
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', $objectType)]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports comments.
	 * 
	 * @param	string		$objectType
	 * @param	string		$importer
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportComments($objectType, $importer, $offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_comment
			WHERE		objectTypeID = ?
			ORDER BY	commentID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', $objectType)]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter($importer)->import($row['commentID'], [
				'objectID' => $row['objectID'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Counts comment responses.
	 *
	 * @param	string		$objectType
	 */
	private function countCommentResponses($objectType) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_comment_response
			WHERE	commentID IN (SELECT commentID FROM wcf".$this->dbNo."_comment WHERE objectTypeID = ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', $objectType)]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports profile Comment responses.
	 *
	 * @param	string		$objectType
	 * @param	string		$importer
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportCommentResponses($objectType, $importer, $offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_comment_response
			WHERE		commentID IN (SELECT commentID FROM wcf".$this->dbNo."_comment WHERE objectTypeID = ?)
			ORDER BY	responseID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.comment.commentableContent', $objectType)]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter($importer)->import($row['responseID'], [
				'commentID' => $row['commentID'],
				'time' => $row['time'],
				'userID' => $row['userID'],
				'username' => $row['username'],
				'message' => $row['message'],
			]);
		}
	}
	
	/**
	 * Counts likes.
	 *
	 * @param	string		$objectType
	 */
	private function countLikes($objectType) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_like
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.like.likeableObject', $objectType)]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports likes.
	 *
	 * @param	string		$objectType
	 * @param	string		$importer
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportLikes($objectType, $importer, $offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_like
			WHERE		objectTypeID = ?
			ORDER BY	likeID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.like.likeableObject', $objectType)]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter($importer)->import(0, [
				'objectID' => $row['objectID'],
				'objectUserID' => $row['objectUserID'],
				'userID' => $row['userID'],
				'likeValue' => $row['likeValue'],
				'time' => $row['time']
			]);
		}
	}
	
	/**
	 * Returns the number of attachments.
	 * 
	 * @param	string		$objectType
	 * @return	integer
	 */
	private function countAttachments($objectType) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".$this->dbNo."_attachment
			WHERE	objectTypeID = ?
				AND objectID IS NOT NULL";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.attachment.objectType', $objectType)]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports attachments.
	 * 
	 * @param	string		$objectType
	 * @param	string		$importer
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportAttachments($objectType, $importer, $offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_attachment
			WHERE		objectTypeID = ?
					AND objectID IS NOT NULL
			ORDER BY	attachmentID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.attachment.objectType', $objectType)]);
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . 'attachments/' . substr($row['fileHash'], 0, 2) . '/' . $row['attachmentID'] . '-' . $row['fileHash'];
			
			ImportHandler::getInstance()->getImporter($importer)->import($row['attachmentID'], [
				'objectID' => $row['objectID'],
				'userID' => $row['userID'] ?: null,
				'filename' => $row['filename'],
				'filesize' => $row['filesize'],
				'fileType' => $row['fileType'],
				'fileHash' => $row['fileHash'],
				'isImage' => $row['isImage'],
				'width' => $row['width'],
				'height' => $row['height'],
				'downloads' => $row['downloads'],
				'lastDownloadTime' => $row['lastDownloadTime'],
				'uploadTime' => $row['uploadTime'],
				'showOrder' => $row['showOrder']
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
	 * @param	string		$objectType
	 * @param	integer[]	$objectIDs
	 * @return	string[][]
	 */
	private function getTags($objectType, array $objectIDs) {
		$tags = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('tag_to_object.objectTypeID = ?', [$this->getObjectTypeID('com.woltlab.wcf.tagging.taggableObject', $objectType)]);
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
		
		return $tags;
	}
	
	/**
	 * Returns the ids of labels to import.
	 * 
	 * @param	string		$objectType
	 * @param	integer[]	$objectIDs
	 * @return	integer[][]
	 */
	private function getLabels($objectType, array $objectIDs) {
		$labels = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('objectTypeID = ?', [$this->getObjectTypeID('com.woltlab.wcf.label.object', $objectType)]);
		$conditionBuilder->add('objectID IN (?)', [$objectIDs]);
		
		$sql = "SELECT		labelID, objectID
			FROM		wcf".$this->dbNo."_label_object
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($labels[$row['objectID']])) $labels[$row['objectID']] = [];
			$labels[$row['objectID']][] = $row['labelID'];
		}
		
		return $labels;
	}
	
	/**
	 * Returns the id of an object type in the imported system or null if no such
	 * object type exists.
	 * 
	 * @param	string		$definitionName
	 * @param	string		$objectTypeName
	 * @return	integer|null
	 */
	private function getObjectTypeID($definitionName, $objectTypeName) {
		$sql = "SELECT	objectTypeID
			FROM	wcf".$this->dbNo."_object_type
			WHERE	objectType = ?
				AND definitionID = (
					SELECT definitionID FROM wcf".$this->dbNo."_object_type_definition WHERE definitionName = ?
				)";
		$statement = $this->database->prepareStatement($sql, 1);
		$statement->execute([$objectTypeName, $definitionName]);
		$row = $statement->fetchArray();
		if ($row !== false) return $row['objectTypeID'];
		
		return null;
	}
	
	/**
	 * Returns the number of categories.
	 * 
	 * @param	string		$objectType
	 * @return	integer
	 */
	private function countCategories($objectType) {
		$sql = "SELECT	COUNT(*)
			FROM	wcf".$this->dbNo."_category
			WHERE	objectTypeID = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.category', $objectType)]);
		
		return $statement->fetchColumn();
	}
	
	/**
	 * Exports categories.
	 * 
	 * @param	string		$objectType
	 * @param	string		$importer
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportCategories($objectType, $importer, $offset, $limit) {
		$sql = "SELECT		*
			FROM		wcf".$this->dbNo."_category
			WHERE		objectTypeID = ?
			ORDER BY	parentCategoryID, categoryID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([$this->getObjectTypeID('com.woltlab.wcf.category', $objectType)]);
		$categories = $i18nValues = [];
		while ($row = $statement->fetchArray()) {
			$categories[$row['categoryID']] = [
				'title' => $row['title'],
				'description' => $row['description'],
				'parentCategoryID' => $row['parentCategoryID'],
				'showOrder' => $row['showOrder'],
				'time' => $row['time'],
				'isDisabled' => $row['isDisabled']
			];
			
			if (strpos($row['title'], 'wcf.category') === 0) {
				$i18nValues[] = $row['title'];
			}
			if (strpos($row['description'], 'wcf.category') === 0) {
				$i18nValues[] = $row['description'];
			}
		}
		
		$i18nValues = $this->getI18nValues($i18nValues);
		
		foreach ($categories as $categoryID => $categoryData) {
			$i18nData = [];
			if (isset($i18nValues[$categoryData['title']])) $i18nData['title'] = $i18nValues[$categoryData['title']];
			if (isset($i18nValues[$categoryData['description']])) $i18nData['description'] = $i18nValues[$categoryData['description']];
			
			ImportHandler::getInstance()->getImporter($importer)->import($categoryID, $categoryData, ['i18n' => $i18nData]);
		}
	}
	
	/**
	 * Reads i18n values from the source database and filters by known language ids.
	 * 
	 * @param       string[]        $i18nValues     list of items
	 * @return      string[][][]    list of values by language item and language id
	 */
	private function getI18nValues(array $i18nValues) {
		if (empty($i18nValues)) return [];
		
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("language_item.languageItem IN (?)", [$i18nValues]);
		
		$sql = "SELECT          language_item.languageItem, language_item.languageItemValue, language.languageCode
			FROM            wcf".$this->dbNo."_language_item language_item
			LEFT JOIN       wcf".$this->dbNo."_language language
			ON              (language_item.languageID = language.languageID)
			".$conditions;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditions->getParameters());
		
		$i18nValues = [];
		while ($row = $statement->fetchArray()) {
			$language = LanguageFactory::getInstance()->getLanguageByCode($row['languageCode']);
			if ($language === null) continue;
			
			$languageItem = $row['languageItem'];
			if (!isset($i18nValues[$languageItem])) $i18nValues[$languageItem] = [];
			$i18nValues[$languageItem][$language->languageID] = $row['languageItemValue'];
		}
		
		return $i18nValues;
	}
}
