<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wbb\data\board\BoardCache;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\exception\DatabaseException;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\DateUtil;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for vBulletin 3.8.x - vBulletin 4.2.x
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2019 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\System\Exporter
 */
class VB3or4xExporter extends AbstractExporter {
	const FORUMOPTIONS_ACTIVE = 1;
	const FORUMOPTIONS_ALLOWPOSTING = 2;
	const FORUMOPTIONS_CANCONTAINTHREADS = 4;
	const FORUMOPTIONS_MODERATENEWPOST = 8;
	const FORUMOPTIONS_MODERATENEWTHREAD = 16;
	const FORUMOPTIONS_MODERATEATTACH = 32;
	const FORUMOPTIONS_ALLOWBBCODE = 64;
	const FORUMOPTIONS_ALLOWIMAGES = 128;
	const FORUMOPTIONS_ALLOWHTML = 256;
	const FORUMOPTIONS_ALLOWSMILIES = 512;
	const FORUMOPTIONS_ALLOWICONS = 1024;
	const FORUMOPTIONS_ALLOWRATINGS = 2048;
	const FORUMOPTIONS_COUNTPOSTS = 4096;
	const FORUMOPTIONS_CANHAVEPASSWORD = 8192;
	const FORUMOPTIONS_INDEXPOSTS = 16384;
	const FORUMOPTIONS_STYLEOVERRIDE = 32768;
	const FORUMOPTIONS_SHOWONFORUMJUMP = 65536;
	const FORUMOPTIONS_PREFIXREQUIRED = 131072;
	
	const FORUMPERMISSIONS_CANVIEW = 1;
	const FORUMPERMISSIONS_CANVIEWTHREADS = 524288;
	const FORUMPERMISSIONS_CANVIEWOTHERS = 2;
	const FORUMPERMISSIONS_CANSEARCH = 4;
	const FORUMPERMISSIONS_CANEMAIL = 8;
	const FORUMPERMISSIONS_CANPOSTNEW = 16;
	const FORUMPERMISSIONS_CANREPLYOWN = 32;
	const FORUMPERMISSIONS_CANREPLYOTHERS = 64;
	const FORUMPERMISSIONS_CANEDITPOST = 128;
	const FORUMPERMISSIONS_CANDELETEPOST = 256;
	const FORUMPERMISSIONS_CANDELETETHREAD = 512;
	const FORUMPERMISSIONS_CANOPENCLOSE = 1024;
	const FORUMPERMISSIONS_CANMOVE = 2048;
	const FORUMPERMISSIONS_CANGETATTACHMENT = 4096;
	const FORUMPERMISSIONS_CANPOSTATTACHMENT = 8192;
	const FORUMPERMISSIONS_CANPOSTPOLL = 16384;
	const FORUMPERMISSIONS_CANVOTE = 32768;
	const FORUMPERMISSIONS_CANTHREADRATE = 65536;
	const FORUMPERMISSIONS_FOLLOWFORUMMODERATION = 131072;
	const FORUMPERMISSIONS_CANSEEDELNOTICE = 262144;
	const FORUMPERMISSIONS_CANTAGOWN = 1048576;
	const FORUMPERMISSIONS_CANTAGOTHERS = 2097152;
	const FORUMPERMISSIONS_CANDELETETAGOWN = 4194304;
	const FORUMPERMISSIONS_CANSEETHUMBNAILS = 8388608;
	
	const ATTACHFILE_DATABASE = 0;
	const ATTACHFILE_FILESYSTEM = 1;
	const ATTACHFILE_FILESYSTEM_SUBFOLDER = 2;
	
	const GALLERY_DATABASE = 'db';
	const GALLERY_FILESYSTEM = 'fs';
	const GALLERY_FILESYSTEM_DIRECT_THUMBS = 'fs_directthumb';
	
	
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
		'com.woltlab.wbb.like' => 'Likes',
		'com.woltlab.wcf.label' => 'Labels',
		'com.woltlab.wbb.acl' => 'ACLs',
		'com.woltlab.wcf.smiley.category' => 'SmileyCategories',
		'com.woltlab.wcf.smiley' => 'Smilies',

		'com.woltlab.gallery.album' => 'GalleryAlbums',
		'com.woltlab.gallery.image' => 'GalleryImages',
		'com.woltlab.gallery.image.comment' => 'GalleryComments',
		
		'com.woltlab.calendar.category' => 'CalendarCategories',
		'com.woltlab.calendar.event' => 'CalendarEvents',
		'com.woltlab.calendar.event.date' => 'CalendarEventDates',
		
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
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
				'com.woltlab.wcf.conversation.label'
			],
			'com.woltlab.gallery.image' => [
				'com.woltlab.gallery.album',
				'com.woltlab.gallery.image.comment'
			],
			'com.woltlab.calendar.event' => [
				'com.woltlab.calendar.category'
			],
			'com.woltlab.blog.entry' => [
				'com.woltlab.blog.entry.attachment',
				'com.woltlab.blog.entry.comment'
			],
			'com.woltlab.wcf.smiley' => []
		];
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$templateversion = $this->readOption('templateversion');
		
		if (version_compare($templateversion, '3.8.0', '<')) throw new DatabaseException('Cannot import less than vB 3.8.x', $this->database);
		if (version_compare($templateversion, '4.3.0 alpha 1', '>=')) throw new DatabaseException('Cannot import greater than vB 4.2.x', $this->database);
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'includes/version_vbulletin.php')) return false;
		}
		
		if (in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) {
			if ($this->readOption('attachfile') != self::ATTACHFILE_DATABASE) {
				$path = $this->readOption('attachpath');
				if (!StringUtil::startsWith($path, '/')) $path = realpath($this->fileSystemPath.$path);
				if (!is_dir($path)) return false;
			}
		}
		
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) {
			if ($this->readOption('usefileavatar')) {
				$path = $this->readOption('avatarpath');
				if (!StringUtil::startsWith($path, '/')) $path = realpath($this->fileSystemPath.$path);
				if (!is_dir($path)) return false;
			}
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
			}
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
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
			$queue[] = 'com.woltlab.wcf.smiley.category';
			$queue[] = 'com.woltlab.wcf.smiley';
		}
		
		// gallery
		if (in_array('com.woltlab.gallery.image', $this->selectedData)) {
			if (in_array('com.woltlab.gallery.album', $this->selectedData)) $queue[] = 'com.woltlab.gallery.album';
			$queue[] = 'com.woltlab.gallery.image';
			if (in_array('com.woltlab.gallery.image.comment', $this->selectedData)) $queue[] = 'com.woltlab.gallery.image.comment';
		}
		
		// calendar
		if (in_array('com.woltlab.calendar.event', $this->selectedData)) {
			if (in_array('com.woltlab.calendar.category', $this->selectedData)) $queue[] = 'com.woltlab.calendar.category';
			$queue[] = 'com.woltlab.calendar.event';
		}
		
		// blog
		if (in_array('com.woltlab.blog.entry', $this->selectedData)) {
			try {
				// Check whether the blog appears to be available.
				$sql = "SELECT	COUNT(*)
					FROM	".$this->databasePrefix."blog";
				$statement = $this->database->prepareStatement($sql);
				$statement->execute();
				$dummy = $statement->fetchSingleColumn();
				
				// If we reach this place then the above query succeeded, thus
				// the blog appears to be available and we add it to the queue.
				$queue[] = 'com.woltlab.blog.entry';
				if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.attachment';
				if (in_array('com.woltlab.blog.entry.comment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.comment';
			}
			catch (DatabaseException $e) {
				// The blog does not appear to be available.
			}
		}
		
		return $queue;
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usergroup";
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
			FROM		".$this->databasePrefix."usergroup
			ORDER BY	usergroupid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			switch ($row['usergroupid']) {
				case 1:
					$groupType = UserGroup::GUESTS;
				break;
				case 2:
					$groupType = UserGroup::USERS;
				break;
				default:
					$groupType = UserGroup::OTHER;
				break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['usergroupid'], [
				'groupName' => $row['title'],
				'groupDescription' => $row['description'],
				'groupType' => $groupType,
				'userOnlineMarking' => $row['opentag'].'%s'.$row['closetag']
			]);
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID($this->databasePrefix."user", 'userid');
	}
	
	/**
	 * Exports users.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUsers($offset, $limit) {
		// cache user options
		$userOptions = [];
		$sql = "SELECT	*
			FROM	".$this->databasePrefix."profilefield";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if ($row['type'] == 'select_multiple' || $row['type'] == 'checkbox') {
				$row['data'] = @unserialize($row['data']);
			}
			
			$userOptions[] = $row;
		}
		
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		userfield.*, user_table.*, textfield.*, useractivation.type AS activationType, useractivation.emailchange, userban.liftdate, userban.reason AS banReason
			FROM		".$this->databasePrefix."user user_table
			LEFT JOIN	".$this->databasePrefix."usertextfield textfield
			ON		user_table.userid = textfield.userid
			LEFT JOIN	".$this->databasePrefix."useractivation useractivation
			ON		user_table.userid = useractivation.userid
			LEFT JOIN	".$this->databasePrefix."userban userban
			ON		user_table.userid = userban.userid
			LEFT JOIN	".$this->databasePrefix."userfield userfield
			ON		userfield.userid = user_table.userid
			WHERE		user_table.userid BETWEEN ? AND ?
			ORDER BY	user_table.userid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'username' => StringUtil::decodeHTML($row['username']),
				'password' => null,
				'email' => StringUtil::decodeHTML($row['email']),
				'registrationDate' => $row['joindate'],
				'banned' => $row['liftdate'] !== null && $row['liftdate'] == 0 ? 1 : 0,
				'banReason' => StringUtil::decodeHTML($row['banReason']),
				'activationCode' => $row['activationType'] !== null && $row['activationType'] == 0 && $row['emailchange'] == 0 ? UserRegistrationUtil::getActivationCode() : 0, // vB's codes are strings
				'oldUsername' => '',
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['ipaddress']), // TODO: check whether this is the registration IP
				'signature' => self::fixBBCodes($row['signature']),
				'userTitle' => ($row['customtitle'] != 0) ? StringUtil::decodeHTML($row['usertitle']) : '',
				'lastActivityTime' => $row['lastactivity']
			];
			
			$options = [];
			if ($row['birthday']) {
				$options['birthday'] = self::convertBirthday($row['birthday']);
			}
			
			$additionalData = [
				'groupIDs' => explode(',', $row['membergroupids'].','.$row['usergroupid']),
				'options' => $options
			];
			
			// handle user options
			foreach ($userOptions as $userOption) {
				$optionID = $userOption['profilefieldid'];
				if (isset($row['field'.$optionID])) {
					$userOptionValue = $row['field'.$optionID];
					if ($userOptionValue && ($userOption['type'] == 'select_multiple' || $userOption['type'] == 'checkbox')) {
						if (is_array($userOption['data'])) {
							$newUserOptionValue = '';
							foreach ($userOption['data'] as $key => $value) {
								if ($userOptionValue & pow(2, $key)) {
									if (!empty($newUserOptionValue)) $newUserOptionValue .= "\n";
									$newUserOptionValue .= $value;
								}
							}
							$userOptionValue = $newUserOptionValue;
						}
					}
					
					$additionalData['options'][$optionID] = $userOptionValue;
				}
			}
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(['vb3:'.$row['password'].':'.$row['salt'], $newUserID]);
			}
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT
			(
				SELECT	COUNT(*)
				FROM	".$this->databasePrefix."usertitle
			)
			+
			(
				SELECT COUNT(*)
				FROM	".$this->databasePrefix."usergroup
				WHERE		usergroupid NOT IN(?, ?)
					AND	usertitle <> ?
			) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([1, 2, '']);
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
		$sql = "(
				SELECT	usertitleid, 2 AS groupID, minposts, title
				FROM	".$this->databasePrefix."usertitle
			)
			UNION
			(
				SELECT	('g-' || usergroupid) AS usertitleid, usergroupid AS groupID, 0 AS minposts, usertitle AS title
				FROM	".$this->databasePrefix."usergroup
				WHERE		usergroupid NOT IN(?, ?)
					AND	usertitle <> ?
			)
			ORDER BY	usertitleid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([1, 2, '']);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['usertitleid'], [
				'groupID' => $row['groupID'],
				'requiredPoints' => $row['minposts'] * 5,
				'rankTitle' => StringUtil::decodeHTML($row['title'])
			]);
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usertextfield
			WHERE	buddylist <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['']);
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
		$sql = "SELECT		userid, buddylist
			FROM		".$this->databasePrefix."usertextfield
			WHERE		buddylist <> ?
			ORDER BY	userid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['']);
		while ($row = $statement->fetchArray()) {
			$buddies = array_unique(ArrayUtil::toIntegerArray(explode(' ', $row['buddylist'])));
			foreach ($buddies as $buddy) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, [
					'userID' => $row['userid'],
					'followUserID' => $buddy
				]);
			}
		}
	}
	
	/**
	 * Counts guestbook entries.
	 */
	public function countGuestbookEntries() {
		return $this->__getMaxID($this->databasePrefix."visitormessage", 'vmid');
	}
	
	/**
	 * Exports guestbook entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGuestbookEntries($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."visitormessage
			WHERE		vmid BETWEEN ? AND ?
			ORDER BY	vmid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['vmid'], [
				'objectID' => $row['userid'],
				'userID' => $row['postuserid'],
				'username' => StringUtil::decodeHTML($row['postusername']),
				'message' => StringUtil::decodeHTML($row['pagetext']),
				'time' => $row['dateline']
			]);
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."customavatar";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 * @throws	\Exception
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		customavatar.*, user.avatarrevision
			FROM		".$this->databasePrefix."customavatar customavatar
			LEFT JOIN	".$this->databasePrefix."user user
			ON		user.userid = customavatar.userid
			ORDER BY	customavatar.userid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$file = null;
			
			try {
				if ($this->readOption('usefileavatar')) {
					$file = $this->readOption('avatarpath');
					if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
					$file = FileUtil::addTrailingSlash($file).'avatar'.$row['userid'].'_'.$row['avatarrevision'].'.gif';
				}
				else {
					$file = FileUtil::getTemporaryFilename('avatar_');
					file_put_contents($file, $row['filedata']);
				}
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['userid'], [
					'avatarName' => StringUtil::decodeHTML($row['filename']),
					'avatarExtension' => pathinfo($row['filename'], PATHINFO_EXTENSION),
					'width' => $row['width'],
					'height' => $row['height'],
					'userID' => $row['userid']
				], ['fileLocation' => $file]);
				
				if (!$this->readOption('usefileavatar')) unlink($file);
			}
			catch (\Exception $e) {
				if (!$this->readOption('usefileavatar') && $file) @unlink($file);
				
				throw $e;
			}
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profilefield";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
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
		$sql = "SELECT	*
			FROM	".$this->databasePrefix."profilefield";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$editable = 0;
			switch ($row['editable']) {
				case 0:
					$editable = UserOption::EDITABILITY_ADMINISTRATOR;
					break;
				case 1:
				case 2:
					$editable = UserOption::EDITABILITY_ALL;
					break;
			}
				
			$visible = UserOption::VISIBILITY_ALL;
			if ($row['hidden']) {
				$visible = UserOption::VISIBILITY_ADMINISTRATOR;
			}
			
			// get select options
			$selectOptions = [];
			if ($row['type'] == 'radio' || $row['type'] == 'select' || $row['type'] == 'select_multiple' || $row['type'] == 'checkbox') {
				$selectOptions = @unserialize($row['data']);
				
				if (!is_array($selectOptions)) {
					$selectOptions = @unserialize(mb_convert_encoding($row['data'], 'ISO-8859-1', 'UTF-8'));
					if (!is_array($selectOptions)) continue;
					
					$selectOptions = array_map(function ($item) {
						return mb_convert_encoding($item, 'UTF-8', 'ISO-8859-1');
					}, $selectOptions);
				}
			}
			
			// get option type
			$optionType = 'text';
			switch ($row['type']) {
				case 'textarea':
					$optionType = 'textarea';
					break;
				case 'radio':
					$optionType = 'radioButton';
					break;
				case 'select':
					$optionType = 'select';
					break;
				case 'select_multiple':
				case 'checkbox':
					$optionType = 'multiSelect';
					break;
			}
			
			// get default value
			$defaultValue = '';
			switch ($row['type']) {
				case 'input':
				case 'textarea':
					$defaultValue = $row['data'];
					break;
				case 'radio':
				case 'select':
					if ($row['def']) {
						// use first radio option
						$defaultValue = reset($selectOptions);
					}
					break;
			}
			
			// get required status
			$required = $askDuringRegistration = 0;
			switch ($row['required']) {
				case 1:
				case 3:
					$required = 1;
					break;
				case 2:
					$askDuringRegistration = 1;
					break;
			}
			
			// get field name
			$fieldName = 'field'.$row['profilefieldid'];
			$sql = "SELECT	text
				FROM	".$this->databasePrefix."phrase
				WHERE	languageid = ?
					AND varname = ?";
			$statement2 = $this->database->prepareStatement($sql);
			$statement2->execute([0, 'field'.$row['profilefieldid'].'_title']);
			$row2 = $statement2->fetchArray();
			if ($row2 !== false) {
				$fieldName = $row2['text'];
			}
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['profilefieldid'], [
				'categoryName' => 'profile.personal',
				'optionType' => $optionType,
				'defaultValue' => $defaultValue,
				'validationPattern' => $row['regex'],
				'selectOptions' => implode("\n", $selectOptions),
				'required' => $required,
				'askDuringRegistration' => $askDuringRegistration,
				'searchable' => $row['searchable'],
				'editable' => $editable,
				'visible' => $visible,
				'showOrder' => $row['displayorder']
			], ['name' => $fieldName]);
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usertextfield
			WHERE		pmfolders IS NOT NULL
				AND	pmfolders <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation folders.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		userid, pmfolders
			FROM		".$this->databasePrefix."usertextfield
			WHERE		pmfolders IS NOT NULL
				AND	pmfolders <> ?
			ORDER BY	userid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['']);
		while ($row = $statement->fetchArray()) {
			$convert = false;
			// vBulletin relies on undefined behaviour by default, we cannot know in which
			// encoding the data was saved
			$pmfolders = @unserialize($row['pmfolders']);
			if (!is_array($pmfolders)) {
				// try to convert it to the most common encoding
				$convert = true;
				$pmfolders = @unserialize(mb_convert_encoding($row['pmfolders'], 'ISO-8859-1', 'UTF-8'));
				
				// still unparseable
				if (!is_array($pmfolders)) continue;
			}
			
			foreach ($pmfolders as $key => $val) {
				// convert back to utf-8
				if ($convert) $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1');
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['userid'].'-'.$key, [
					'userID' => $row['userid'],
					'label' => mb_substr($val, 0, 80)
				]);
			}
		}
	}
	
	/**
	 * Creates a conversation id out of the old parentpmid and the participants.
	 * 
	 * This ensures that only the actual receivers of a pm are able to see it
	 * after import, while minimizing the number of conversations.
	 *
	 * @param	integer		$parentpmid
	 * @param	integer[]	$participants
	 * @return	string
	 */
	private function getConversationID($parentpmid, array $participants) {
		$conversationID = $parentpmid;
		$participants = array_unique($participants);
		sort($participants);
		$conversationID .= '-'.implode(',', $participants);
		
		return StringUtil::getHash($conversationID);
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		return $this->__getMaxID($this->databasePrefix."pm", 'pmid');
	}
	
	/**
	 * Exports conversations.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		pm.*, text.*,
					(
						SELECT	GROUP_CONCAT(pm2.userid)
						FROM	".$this->databasePrefix."pm pm2
						WHERE	pm.pmtextid = pm2.pmtextid
					) AS participants
			FROM		".$this->databasePrefix."pm pm
			INNER JOIN	".$this->databasePrefix."pmtext text
			ON		pm.pmtextid = text.pmtextid
			WHERE		pm.pmid BETWEEN ? AND ?
			ORDER BY	pm.pmid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$participants = explode(',', $row['participants']);
			$participants[] = $row['fromuserid'];
			$conversationID = $this->getConversationID($row['parentpmid'] ?: $row['pmid'], $participants);
			
			if (ImportHandler::getInstance()->getNewID('com.woltlab.wcf.conversation', $conversationID) !== null) continue;
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($conversationID, [
				'subject' => StringUtil::decodeHTML($row['title']),
				'time' => $row['dateline'],
				'userID' => $row['fromuserid'],
				'username' => StringUtil::decodeHTML($row['fromusername']),
				'isDraft' => 0
			]);
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		return $this->__getMaxID($this->databasePrefix."pmtext", 'pmtextid');
	}
	
	/**
	 * Exports conversation messages.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		pmtext.*,
					(
					".$this->database->handleLimitParameter("SELECT IF(pm.parentpmid = 0, pm.pmid, pm.parentpmid) FROM ".$this->databasePrefix."pm pm WHERE pmtext.pmtextid = pm.pmtextid", 1)."
					) AS conversationID,
					(
						SELECT	GROUP_CONCAT(pm.userid)
						FROM	".$this->databasePrefix."pm pm
						WHERE	pmtext.pmtextid = pm.pmtextid
					) AS participants
			FROM		".$this->databasePrefix."pmtext pmtext
			WHERE		pmtext.pmtextid BETWEEN ? AND ?
			ORDER BY	pmtext.pmtextid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$participants = explode(',', $row['participants']);
			$participants[] = $row['fromuserid'];
			$conversationID = $this->getConversationID($row['conversationID'], $participants);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['pmtextid'], [
				'conversationID' => $conversationID,
				'userID' => $row['fromuserid'],
				'username' => StringUtil::decodeHTML($row['fromusername']),
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['dateline']
			]);
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."pm";
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
		$sql = "SELECT		pm.*, user.username, pmtext.touserarray, pmtext.dateline, pmtext.fromuserid,
					(
						SELECT	GROUP_CONCAT(pm2.userid)
						FROM	".$this->databasePrefix."pm pm2
						WHERE	pm.pmtextid = pm2.pmtextid
					) AS participants
			FROM		".$this->databasePrefix."pm pm
			INNER JOIN	".$this->databasePrefix."user user
			ON		pm.userid = user.userid
			INNER JOIN	".$this->databasePrefix."pmtext pmtext
			ON		pmtext.pmtextid = pm.pmtextid
			ORDER BY	pm.pmid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$participants = explode(',', $row['participants']);
			$participants[] = $row['fromuserid'];
			$conversationID = $this->getConversationID($row['parentpmid'] ?: $row['pmid'], $participants);
			
			// vBulletin relies on undefined behaviour by default, we cannot know in which
			// encoding the data was saved
			// this may cause some hidden participants to become visible
			$recipients = @unserialize($row['touserarray']);
			if (!is_array($recipients)) $recipients = [];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, [
				'conversationID' => $conversationID,
				'participantID' => $row['userid'],
				'username' => StringUtil::decodeHTML($row['username'] ?: ''),
				'hideConversation' => 0, // there is no trash
				'isInvisible' => (isset($recipients['bcc']) && isset($recipients['bcc'][$row['userid']])) ? 1 : 0,
				'lastVisitTime' => $row['messageread'] ? $row['dateline'] : 0
			], ['labelIDs' => ($row['folderid'] > 0) ? [$row['userid'].'-'.$row['folderid']] : []]);
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."forum";
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
			FROM		".$this->databasePrefix."forum
			ORDER BY	forumid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parentid']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 *
	 * @param	integer		$parentID
	 */
	protected function exportBoardsRecursively($parentID = -1) {
		if (!isset($this->boardCache[$parentID])) return;
		$getDaysPrune = function ($value) {
			if ($value == -1) return 1000;
			
			$availableDaysPrune = [1, 3, 7, 14, 30, 60, 100, 365];
			
			foreach ($availableDaysPrune as $daysPrune) {
				if ($value <= $daysPrune) return $daysPrune;
			}
			
			return 1000;
		};
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['forumid'], [
				'parentID' => $board['parentid'] != -1 ? $board['parentid'] : null,
				'position' => $board['displayorder'],
				'boardType' => $board['link'] ? Board::TYPE_LINK : ($board['options'] & self::FORUMOPTIONS_CANCONTAINTHREADS ? Board::TYPE_BOARD : Board::TYPE_CATEGORY),
				'title' => StringUtil::decodeHTML($board['title_clean']),
				'description' => StringUtil::decodeHTML($board['description_clean']),
				'descriptionUseHtml' => 0,
				'externalURL' => $board['link'],
				'countUserPosts' => $board['options'] & self::FORUMOPTIONS_COUNTPOSTS ? 1 : 0,
				'daysPrune' => $getDaysPrune($board['daysprune']),
				'enableMarkingAsDone' => 0,
				'ignorable' => 1,
				'isClosed' => $board['options'] & self::FORUMOPTIONS_ALLOWPOSTING ? 0 : 1,
				'isInvisible' => $board['options'] & self::FORUMOPTIONS_ACTIVE ? 0 : 1,
				'searchable' => $board['options'] & self::FORUMOPTIONS_INDEXPOSTS ? 1 : 0,
				'searchableForSimilarThreads' => $board['options'] & self::FORUMOPTIONS_INDEXPOSTS ? 1 : 0,
				'clicks' => 0,
				'posts' => $board['replycount'],
				'threads' => $board['threadcount']
			]);
			
			$this->exportBoardsRecursively($board['forumid']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->__getMaxID($this->databasePrefix."thread", 'threadid');
	}
	
	/**
	 * Exports threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		thread.*,
					(
						SELECT	MAX(dateline)
						FROM	".$this->databasePrefix."moderatorlog moderatorlog
						WHERE		thread.threadid = moderatorlog.threadid
							AND	type = ?
					) AS deleteTime
			FROM		".$this->databasePrefix."thread thread
			WHERE		thread.threadid BETWEEN ? AND ?
			ORDER BY	thread.threadid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([14, $offset + 1, $offset + $limit]); // 14 = soft delete
		while ($row = $statement->fetchArray()) {
			$data = [
				'boardID' => $row['forumid'],
				'topic' => StringUtil::decodeHTML($row['title']),
				'time' => $row['dateline'],
				'userID' => $row['postuserid'],
				'username' => StringUtil::decodeHTML($row['postusername']),
				'views' => $row['views'],
				'isAnnouncement' => 0,
				'isSticky' => $row['sticky'],
				'isDisabled' => $row['visible'] == 1 ? 0 : 1, // visible = 2 is deleted
				'isClosed' => $row['open'] == 1 ? 0 : 1, // open = 10 is redirect
				'isDeleted' => $row['visible'] == 2 ? 1 : 0,
				'movedThreadID' => ($row['open'] == 10 && $row['pollid']) ? $row['pollid'] : null, // target thread is saved in pollid...
				'movedTime' => 0,
				'isDone' => 0,
				'deleteTime' => $row['deleteTime'] ?: 0,
				'lastPostTime' => $row['lastpost']
			];
			$additionalData = [];
			if ($row['prefixid']) $additionalData['labels'] = [$row['prefixid']];
			if ($row['taglist'] !== null) {
				$tags = ArrayUtil::trim(explode(',', $row['taglist']));
				$additionalData['tags'] = $tags;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['threadid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID($this->databasePrefix."post", 'postid');
	}
	
	/**
	 * Exports posts.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		post.*,
					postedithistory.dateline AS lastEditTime, postedithistory.username AS editor, postedithistory.userid AS editorID,
					postedithistory.reason AS editReason,
					(
						SELECT	COUNT(*) - 1
						FROM	".$this->databasePrefix."postedithistory postedithistory3
						WHERE	postedithistory3.postid = post.postid
					) AS editCount
			FROM		".$this->databasePrefix."post post
			LEFT JOIN	".$this->databasePrefix."postedithistory postedithistory
			ON		postedithistory.postedithistoryid = (
						SELECT	MAX(postedithistoryid)
						FROM	".$this->databasePrefix."postedithistory postedithistory2
						WHERE	postedithistory2.postid = post.postid
					)
			WHERE		post.postid BETWEEN ? AND ?
			ORDER BY	post.postid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			if (isset($row['htmlState']) && $row['htmlState'] == 'on_nl2br') {
				$row['pagetext'] = str_replace("\n", '<br />', StringUtil::unifyNewlines($row['pagetext']));
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['postid'], [
				'threadID' => $row['threadid'],
				'userID' => $row['userid'],
				'username' => StringUtil::decodeHTML($row['username']),
				'subject' => StringUtil::decodeHTML($row['title']),
				'message' => self::fixBBCodes($row['pagetext']),
				'time' => $row['dateline'],
				'isDeleted' => $row['visible'] == 2 ? 1 : 0,
				'isDisabled' => $row['visible'] == 0 ? 1 : 0,
				'isClosed' => 0,
				'editorID' => $row['editorID'] ?: null,
				'editor' => $row['editor'] ?: '',
				'lastEditTime' => $row['lastEditTime'] ?: 0,
				'editCount' => $row['editCount'] && $row['editCount'] > 0 ? $row['editCount'] : 0,
				'editReason' => $row['editReason'] ?: '',
				'attachments' => $row['attach'],
				'enableHtml' => isset($row['htmlState']) && $row['htmlState'] != 'off' ? 1 : 0,
				'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress'])
			]);
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments('Post');
	}
	
	/**
	 * Exports post attachments.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 * @throws	\Exception
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('Post', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		return $this->__getMaxID($this->databasePrefix."subscribethread", 'subscribethreadid');
	}
	
	/**
	 * Exports watched threads.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."subscribethread
			WHERE		subscribethreadid BETWEEN ? AND ?
			ORDER BY	subscribethreadid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import($row['subscribethreadid'], [
				'objectID' => $row['threadid'],
				'userID' => $row['userid']
			]);
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."poll";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([]);
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
		$sql = "SELECT		poll.*, thread.firstpostid
			FROM		".$this->databasePrefix."poll poll
			LEFT JOIN	".$this->databasePrefix."thread thread
			ON			poll.pollid = thread.pollid
					AND	thread.open <> ?
			ORDER BY	poll.pollid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([10]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollid'], [
				'objectID' => $row['firstpostid'],
				'question' => StringUtil::encodeHTML($row['question']),
				'time' => $row['dateline'],
				'endTime' => $row['timeout'] ? $row['dateline'] + $row['timeout'] * 86400 : 0,
				'isChangeable' => 0,
				'isPublic' => $row['public'] ? 1 : 0,
				'sortByVotes' => 0,
				'maxVotes' => $row['multiple'] ? $row['numberoptions'] : 1,
				'votes' => $row['voters']
			]);
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		return $this->countPolls();
	}
	
	/**
	 * Exports poll options.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."poll
			ORDER BY	pollid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$options = explode('|||', $row['options']);
			$votes = explode('|||', $row['votes']);
			
			if (count($options) != count($votes)) continue;
			
			$i = 1;
			foreach ($options as $key => $option) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pollid'].'-'.$i, [
					'pollID' => $row['pollid'],
					'optionValue' => $option,
					'showOrder' => $i,
					'votes' => $votes[$key]
				]);
				
				$i++;
			}
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		return $this->__getMaxID($this->databasePrefix."pollvote", 'pollvoteid');
	}
	
	/**
	 * Exports poll option votes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."pollvote
			WHERE		pollvoteid BETWEEN ? AND ?
			ORDER BY	pollvoteid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, [
				'pollID' => $row['pollid'],
				'optionID' => $row['pollid'].'-'.$row['voteoption'],
				'userID' => $row['userid']
			]);
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		return $this->__getMaxID($this->databasePrefix."reputation", 'reputationid');
	}
	
	/**
	 * Exports likes.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportLikes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."reputation
			WHERE		reputationid BETWEEN ? AND ?
			ORDER BY	reputationid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, [
				'objectID' => $row['postid'],
				'objectUserID' => $row['userid'] ?: null,
				'userID' => $row['whoadded'],
				'likeValue' => ($row['reputation'] > 0) ? Like::LIKE : Like::DISLIKE,
				'time' => $row['dateline']
			]);
		}
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."prefix";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports labels.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportLabels($offset, $limit) {
		if (!$offset) {
			$boardIDs = array_keys(BoardCache::getInstance()->getBoards());
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
			
			$sql = "SELECT		*
				FROM		".$this->databasePrefix."prefixset
				ORDER BY	prefixsetid";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute();
			
			while ($row = $statement->fetchArray()) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($row['prefixsetid'], [
					'groupName' => $row['prefixsetid']
				], ['objects' => [$objectType->objectTypeID => $boardIDs]]);
			}
		}
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."prefix
			ORDER BY	prefixid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($row['prefixid'], [
				'groupID' => $row['prefixsetid'],
				'label' => mb_substr($row['prefixid'], 0, 80)
			]);
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."forumpermission";
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
		$mapping = [
			'canViewBoard' => self::FORUMPERMISSIONS_CANVIEW,
			'canEnter' => self::FORUMPERMISSIONS_CANVIEWTHREADS,
			'canReadThread' => self::FORUMPERMISSIONS_CANVIEWOTHERS,
			'canStartThread' => self::FORUMPERMISSIONS_CANPOSTNEW,
			'canReplyThread' => self::FORUMPERMISSIONS_CANREPLYOTHERS,
			'canReplyOwnThread' => self::FORUMPERMISSIONS_CANREPLYOWN,
			'canEditOwnPost' => self::FORUMPERMISSIONS_CANEDITPOST,
			'canDeleteOwnPost' => self::FORUMPERMISSIONS_CANDELETEPOST | self::FORUMPERMISSIONS_CANDELETETHREAD,
			'canDownloadAttachment' => self::FORUMPERMISSIONS_CANGETATTACHMENT,
			'canViewAttachmentPreview' => self::FORUMPERMISSIONS_CANSEETHUMBNAILS,
			'canUploadAttachment' => self::FORUMPERMISSIONS_CANPOSTATTACHMENT,
			'canStartPoll' => self::FORUMPERMISSIONS_CANPOSTPOLL,
			'canVotePoll' => self::FORUMPERMISSIONS_CANVOTE
		];
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."forumpermission
			ORDER BY	forumpermissionid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		while ($row = $statement->fetchArray()) {
			foreach ($mapping as $permission => $bits) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, [
					'objectID' => $row['forumid'],
					'groupID' => $row['usergroupid'],
					'optionValue' => ($row['forumpermissions'] & $bits) ? 1 : 0
				], [
					'optionName' => $permission
				]);
			}
		}
	}
	
	/**
	 * Counts smilies.
	 */
	public function countSmilies() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."smilie";
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
			FROM		".$this->databasePrefix."smilie
			ORDER BY	smilieid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([]);
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . $row['smiliepath'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smilieid'], [
				'smileyTitle' => StringUtil::decodeHTML($row['title']),
				'smileyCode' => $row['smilietext'],
				'showOrder' => $row['displayorder'],
				'categoryID' => $row['imagecategoryid']
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Counts smiley categories.
	 */
	public function countSmileyCategories() {
		$sql = "SELECT		COUNT(*) AS count
			FROM		".$this->databasePrefix."imagecategory
			WHERE		imagetype = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([3]);
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
			FROM		".$this->databasePrefix."imagecategory
			WHERE		imagetype = ?
			ORDER BY	imagecategoryid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([3]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley.category')->import($row['imagecategoryid'], [
				'title' => StringUtil::decodeHTML($row['title']),
				'parentCategoryID' => 0,
				'showOrder' => $row['displayorder']
			]);
		}
	}
	
	/**
	 * Counts gallery albums.
	 */
	public function countGalleryAlbums() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."album";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports gallery albums.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryAlbums($offset, $limit) {
		$sql = "SELECT		album.*, user.username
			FROM		".$this->databasePrefix."album album
			LEFT JOIN	".$this->databasePrefix."user user
			ON		album.userid = user.userid
			ORDER BY	albumid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.album')->import($row['albumid'], [
				'userID' => $row['userid'],
				'username' => StringUtil::decodeHTML($row['username'] ?: ''),
				'title' => StringUtil::decodeHTML($row['title']),
				'description' => StringUtil::decodeHTML($row['description']),
				'lastUpdateTime' => $row['lastpicturedate']
			]);
		}
	}
	
	/**
	 * Counts gallery images.
	 */
	public function countGalleryImages() {
		try {
			$sql = "SELECT	COUNT(*) AS count
				FROM	".$this->databasePrefix."picture";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute();
		}
		catch (DatabaseException $e) {
			$sql = "SELECT	COUNT(*) AS count
				FROM	".$this->databasePrefix."attachment
				WHERE	contenttypeid = (SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = 'Album')";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute();
		}
		
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports gallery images.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 * @throws	\Exception
	 */
	public function exportGalleryImages($offset, $limit) {
		try {
			// vb 3
			$sql = "SELECT		picture.*, album.albumid, album.dateline, user.username
				FROM		".$this->databasePrefix."picture picture
				LEFT JOIN	".$this->databasePrefix."albumpicture album
				ON		picture.pictureid = album.pictureid
				LEFT JOIN	".$this->databasePrefix."user user
				ON		picture.userid = user.userid
				ORDER BY	picture.pictureid";
			$statement = $this->database->prepareStatement($sql, $limit, $offset);
			$statement->execute();
			
			$vB = 3;
		}
		catch (DatabaseException $e) {
			// vb 4
			$sql = "SELECT		attachment.*, attachment.contentid AS albumid, filedata.filedata, filedata.extension,
						filedata.filesize, filedata.width, filedata.height, user.username
				FROM		".$this->databasePrefix."attachment attachment
				LEFT JOIN	".$this->databasePrefix."filedata filedata
				ON		attachment.filedataid = filedata.filedataid
				LEFT JOIN	".$this->databasePrefix."user user
				ON		attachment.userid = user.userid
				WHERE		attachment.contenttypeid = (SELECT contenttypeid FROM ".$this->databasePrefix."contenttype contenttype WHERE contenttype.class = 'Album')
				ORDER BY	attachment.attachmentid";
			$statement = $this->database->prepareStatement($sql, $limit, $offset);
			$statement->execute();
			
			$vB = 4;
		}
		while ($row = $statement->fetchArray()) {
			try {
				if ($vB === 4) {
					switch ($this->readOption('attachfile')) {
						case self::ATTACHFILE_DATABASE:
							$file = FileUtil::getTemporaryFilename('attachment_');
							file_put_contents($file, $row['filedata']);
						break;
						case self::ATTACHFILE_FILESYSTEM:
							$file = $this->readOption('attachpath');
							if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
							$file = FileUtil::addTrailingSlash($file);
							$file .= $row['userid'].'/'.(isset($row['filedataid']) ? $row['filedataid'] : $row['attachmentid']).'.attach';
						break;
						case self::ATTACHFILE_FILESYSTEM_SUBFOLDER:
							$file = $this->readOption('attachpath');
							if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
							$file = FileUtil::addTrailingSlash($file);
							$file .= implode('/', str_split($row['userid'])).'/'.(isset($row['filedataid']) ? $row['filedataid'] : $row['attachmentid']).'.attach';
						break;
					}
				}
				else {
					switch ($this->readOption('album_dataloc')) {
						case self::GALLERY_DATABASE:
							$file = FileUtil::getTemporaryFilename('attachment_');
							file_put_contents($file, $row['filedata']);
						break;
						case self::GALLERY_FILESYSTEM:
						case self::GALLERY_FILESYSTEM_DIRECT_THUMBS:
							$file = $this->readOption('album_picpath');
							if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
							$file = FileUtil::addTrailingSlash($file);
							$file .= floor($row['pictureid'] / 1000).'/'.$row['pictureid'].'.picture';
						break;
					}
				}
				
				/** @noinspection PhpUndefinedVariableInspection */
				$additionalData = [
					'fileLocation' => $file
				];
				
				ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image')->import((isset($row['pictureid']) ? $row['pictureid'] : $row['filedataid']), [
					'userID' => $row['userid'] ?: null,
					'username' => StringUtil::decodeHTML($row['username'] ?: ''),
					'albumID' => $row['albumid'] ?: null,
					'title' => StringUtil::decodeHTML($row['caption']),
					'description' => '',
					'filename' => StringUtil::decodeHTML(isset($row['filename']) ? $row['filename'] : ''),
					'fileExtension' => $row['extension'],
					'uploadTime' => $row['dateline'],
					'creationTime' => $row['dateline'],
					'width' => $row['width'],
					'height' => $row['height']
				], $additionalData);
			}
			catch (\Exception $e) {
				/** @noinspection PhpUndefinedVariableInspection */
				if ($vB === 3 && $this->readOption('album_dataloc') == self::GALLERY_DATABASE && $file) @unlink($file);
				/** @noinspection PhpUndefinedVariableInspection */
				if ($vB === 4 && $this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) @unlink($file);
				
				throw $e;
			}
		}
	}
	
	/**
	 * Counts gallery comments.
	 */
	public function countGalleryComments() {
		return $this->__getMaxID($this->databasePrefix."picturecomment", 'commentid');
	}
	
	/**
	 * Exports gallery comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportGalleryComments($offset, $limit) {
		$sql = "SELECT		comment.*, user.username
			FROM		".$this->databasePrefix."picturecomment comment
			LEFT JOIN	".$this->databasePrefix."user user
			ON		comment.postuserid = user.userid
			WHERE		comment.commentid BETWEEN ? AND ?
			ORDER BY	comment.commentid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.gallery.image.comment')->import($row['commentid'], [
				'objectID' => isset($row['pictureid']) ? $row['pictureid'] : $row['filedataid'],
				'userID' => $row['postuserid'] ?: null,
				'username' => StringUtil::decodeHTML($row['username'] ?: ''),
				'message' => $row['pagetext'],
				'time' => $row['dateline']
			]);
		}
	}
	
	/**
	 * Counts calendar categories.
	 */
	public function countCalendarCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."calendar";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
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
			FROM		".$this->databasePrefix."calendar
			ORDER BY	calendarid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.category')->import($row['calendarid'], [
				'title' => StringUtil::decodeHTML($row['title']),
				'description' => StringUtil::decodeHTML($row['description']),
				'parentCategoryID' => 0,
				'showOrder' => $row['displayorder']
			]);
		}
	}
	
	/**
	 * Counts calendar events.
	 */
	public function countCalendarEvents() {
		return $this->__getMaxID($this->databasePrefix."event", 'eventid');
	}
	
	/**
	 * Exports calendar events.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportCalendarEvents($offset, $limit) {
		$sql = "SELECT		event.*, user.username
			FROM		".$this->databasePrefix."event event
			LEFT JOIN	".$this->databasePrefix."user user
			ON		event.userid = user.userid
			WHERE		eventid BETWEEN ? AND ?
			ORDER BY	eventid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		
		$timezones = [];
		foreach (DateUtil::getAvailableTimezones() as $timezone) {
			$dateTimeZone = new \DateTimeZone($timezone);
			$offset = $dateTimeZone->getOffset(new \DateTime("now", $dateTimeZone));
			/** @noinspection PhpIllegalArrayKeyTypeInspection */
			$timezones[round($offset / 360, 0)] = $timezone;
		}
		
		while ($row = $statement->fetchArray()) {
			
			/** @noinspection PhpIllegalArrayKeyTypeInspection */
			$eventDateData = [
				'startTime' => $row['dateline_from'],
				'endTime' => ($row['recurring'] != 0) ? $row['dateline_from'] + 1 : $row['dateline_to'], // vBulletin does not properly support endTime for recurring events
				'isFullDay' => $row['dateline_to'] ? 0 : 1,
				'timezone' => $timezones[round($row['utc'] * 10, 0)],
				'repeatEndType' => 'date',
				'repeatEndDate' => $row['dateline_to'],
				'repeatEndCount' => 1000,
				'firstDayOfWeek' => 1,
				'repeatType' => '',
				'repeatInterval' => 1,
				'repeatWeeklyByDay' => [],
				'repeatMonthlyByMonthDay' => 1,
				'repeatMonthlyDayOffset' => 1,
				'repeatMonthlyByWeekDay' => 0,
				'repeatYearlyByMonthDay' => 1,
				'repeatYearlyByMonth' => 1,
				'repeatYearlyDayOffset' => 1,
				'repeatYearlyByWeekDay' => 1
			];
			
			switch ($row['recurring']) {
				case 0:
					$eventDateData['repeatType'] = '';
				break;
				case 1:
					$eventDateData['repeatType'] = 'daily';
					$eventDateData['repeatInterval'] = $row['recuroption'];
				break;
				case 2:
					$eventDateData['repeatType'] = 'daily';
					$eventDateData['repeatInterval'] = 1;
				break;
				case 3:
					$eventDateData['repeatType'] = 'weekly';
					list($interval, $days) = explode('|', $row['recuroption']);
					$eventDateData['repeatInterval'] = $interval;
					// each day is represented as one bit
					for ($i = 0; $i < 7; $i++) {
						if ($days & (1 << $i)) {
							$eventDateData['repeatWeeklyByDay'][] = $i;
						}
					}
				break;
				case 4:
					$eventDateData['repeatType'] = 'monthlyByDayOfMonth';
					list($day, $interval) = explode('|', $row['recuroption']);
					$eventDateData['repeatInterval'] = $interval;
					$eventDateData['repeatMonthlyByMonthDay'] = $day;
				break;
				case 5:
					$eventDateData['repeatType'] = 'monthlyByDayOfWeek';
					list($offset, $day, $interval) = explode('|', $row['recuroption']);
					$eventDateData['repeatInterval'] = $interval;
					
					// last is -1 for WoltLab and 5 for vBulletin
					$eventDateData['repeatMonthlyDayOffset'] = $offset == 5 ? -1 : $offset;
					
					// week day is one indexed, starting at sunday
					$eventDateData['repeatMonthlyByWeekDay'] = $day - 1;
				break;
				case 6:
					$eventDateData['repeatType'] = 'yearlyByDayOfMonth';
					list($month, $day) = explode('|', $row['recuroption']);
					$eventDateData['repeatYearlyByMonthDay'] = $day;
					$eventDateData['repeatYearlyByMonth'] = $month;
				break;
				case 7:
					$eventDateData['repeatType'] = 'yearlyByDayOfWeek';
					list($offset, $day, $month) = explode('|', $row['recuroption']);
					$eventDateData['repeatYearlyByMonth'] = $month;
					$eventDateData['repeatYearlyDayOffset'] = $offset;
					
					// week day is one indexed, starting at sunday
					$eventDateData['repeatYearlyByWeekDay'] = $day - 1;
				break;
			}
			
			$data = [
				'userID' => $row['userid'] ?: null,
				'username' => $row['username'],
				'subject' => $row['title'],
				'message' => self::fixBBCodes($row['event']),
				'time' => $row['dateline'],
				'eventDate' => serialize($eventDateData)
			];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.calendar.event')->import($row['eventid'], $data, [
				'categories' => [$row['calendarid']],
				'createEventDates' => true
			]);
		}
	}
	
	/**
	 * Counts blog entries.
	 */
	public function countBlogEntries() {
		return $this->__getMaxID($this->databasePrefix."blog", 'blogid');
	}
	
	/**
	 * Exports blog entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntries($offset, $limit) {
		$sql = "SELECT		blog_text.*, blog.*
			FROM		".$this->databasePrefix."blog blog
			LEFT JOIN       ".$this->databasePrefix."blog_text blog_text
			ON		blog_text.blogtextid = blog.firstblogtextid
			WHERE		blog.blogid BETWEEN ? AND ?
			ORDER BY	blog.blogid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'userID' => $row['userid'] ?: null,
				'username' => StringUtil::decodeHTML($row['username'] ?: ''),
				'subject' => StringUtil::decodeHTML($row['title']),
				'message' => self::fixBBCodes($row['pagetext']),
				'time' => $row['dateline'],
				'comments' => $row['comments_visible'],
				'views' => $row['views'],
			];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['blogid'], $data);
		}
	}
	
	/**
	 * Counts blog entry comments.
	 */
	public function countBlogComments() {
		return $this->__getMaxID($this->databasePrefix."blog_text", 'blogtextid');
	}
	
	/**
	 * Exports blog entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogComments($offset, $limit) {
		$sql = "SELECT		blog_text.*
			FROM		".$this->databasePrefix."blog_text blog_text
			WHERE		blogtextid BETWEEN ? AND ?
					AND blogtextid NOT IN (SELECT firstblogtextid FROM ".$this->databasePrefix."blog)
			ORDER BY	blogtextid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment')->import($row['blogtextid'], [
				'objectID' => $row['blogid'],
				'userID' => $row['userid'] ?: null,
				'username' => StringUtil::decodeHTML($row['username'] ?: ''),
				'message' => self::fixBBCodes($row['pagetext']),
				'time' => $row['dateline']
			]);
		}
	}
	
	/**
	 * Counts blog entry attachments.
	 */
	public function countBlogAttachments() {
		return $this->countAttachments('BlogEntry');
	}
	
	/**
	 * Exports post attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 * @throws	\Exception
	 */
	public function exportBlogAttachments($offset, $limit) {
		$this->exportAttachments('BlogEntry', 'com.woltlab.blog.entry.attachment', $offset, $limit);
	}
	
	/**
	 * Returns the number of attachments.
	 *
	 * @param	string		$contentType
	 * @return	integer
	 */
	private function countAttachments($contentType) {
		try {
			// vb 4
			$sql = "SELECT	COUNT(*) AS count
				FROM	".$this->databasePrefix."attachment
				WHERE	contenttypeid = (SELECT contenttypeid FROM ".$this->databasePrefix."contenttype WHERE class = ?)";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute([$contentType]);
		}
		catch (DatabaseException $e) {
			// vb 3
			if ($contentType != 'Post') return 0;
			
			$sql = "SELECT	COUNT(*) AS count
				FROM	".$this->databasePrefix."attachment";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute();
		}
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports attachments.
	 *
	 * @param	string		$contentType
	 * @param	string		$importer
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	private function exportAttachments($contentType, $importer, $offset, $limit) {
		try {
			// vb 4
			$sql = "SELECT		attachment.*, filedata.filedata
				FROM		".$this->databasePrefix."attachment attachment
				LEFT JOIN	".$this->databasePrefix."filedata filedata
				ON		attachment.filedataid = filedata.filedataid
				WHERE		attachment.contenttypeid = (SELECT contenttypeid FROM ".$this->databasePrefix."contenttype contenttype WHERE contenttype.class = ?)
				ORDER BY	attachment.attachmentid";
			$statement = $this->database->prepareStatement($sql, $limit, $offset);
			$statement->execute([$contentType]);
		}
		catch (DatabaseException $e) {
			// vb 3
			if ($contentType != 'Post') return;
			
			$sql = "SELECT		attachment.*, attachment.postid AS contentid
				FROM		".$this->databasePrefix."attachment attachment
				ORDER BY	attachment.attachmentid";
			$statement = $this->database->prepareStatement($sql, $limit, $offset);
			$statement->execute();
		}
		
		while ($row = $statement->fetchArray()) {
			$file = null;
			
			try {
				switch ($this->readOption('attachfile')) {
					case self::ATTACHFILE_DATABASE:
						$file = FileUtil::getTemporaryFilename('attachment_');
						file_put_contents($file, $row['filedata']);
						break;
					case self::ATTACHFILE_FILESYSTEM:
						$file = $this->readOption('attachpath');
						if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
						$file = FileUtil::addTrailingSlash($file);
						$file .= $row['userid'].'/'.(isset($row['filedataid']) ? $row['filedataid'] : $row['attachmentid']).'.attach';
						break;
					case self::ATTACHFILE_FILESYSTEM_SUBFOLDER:
						$file = $this->readOption('attachpath');
						if (!StringUtil::startsWith($file, '/')) $file = realpath($this->fileSystemPath.$file);
						$file = FileUtil::addTrailingSlash($file);
						$file .= implode('/', str_split($row['userid'])).'/'.(isset($row['filedataid']) ? $row['filedataid'] : $row['attachmentid']).'.attach';
						break;
				}
				
				// unable to read file -> abort
				if (!is_file($file) || !is_readable($file)) continue;
				
				ImportHandler::getInstance()->getImporter($importer)->import($row['attachmentid'], [
					'objectID' => $row['contentid'],
					'userID' => $row['userid'] ?: null,
					'filename' => $row['filename'],
					'downloads' => $row['counter'],
					'uploadTime' => $row['dateline'],
					'showOrder' => isset($row['displayOrder']) ? $row['displayOrder'] : 0
				], ['fileLocation' => $file]);
				
				if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE) unlink($file);
			}
			catch (\Exception $e) {
				if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) @unlink($file);
				
				throw $e;
			}
		}
	}
	
	/**
	 * Returns the value of the given option in the imported board.
	 *
	 * @param	string		$optionName
	 * @return	mixed
	 */
	private function readOption($optionName) {
		static $optionCache = [];
		
		if (!isset($optionCache[$optionName])) {
			$sql = "SELECT	value
				FROM	".$this->databasePrefix."setting
				WHERE	varname = ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute([$optionName]);
			$row = $statement->fetchArray();
			
			$optionCache[$optionName] = $row['value'];
		}
		
		return $optionCache[$optionName];
	}
	
	/**
	 * Returns message with fixed BBCodes as used in WCF.
	 *
	 * @param	string		$message
	 * @return	string
	 */
	private static function fixBBCodes($message) {
		static $quoteRegex = null;
		static $quoteCallback = null;
		static $mediaRegex = null;
		
		if ($quoteRegex === null) {
			$quoteRegex = new Regex('\[quote=(.*?);(\d+)\]', Regex::CASE_INSENSITIVE);
			$quoteCallback = function ($matches) {
				$username = str_replace(["\\", "'"], ["\\\\", "\'"], $matches[1]);
				$postID = $matches[2];
				
				$postLink = LinkHandler::getInstance()->getLink('Thread', [
						'application' => 'wbb',
						'postID' => $postID,
						'forceFrontend' => true
					]).'#post'.$postID;
				$postLink = str_replace(["\\", "'"], ["\\\\", "\'"], $postLink);
				
				return "[quote='".$username."','".$postLink."']";
			};
			$mediaRegex = new Regex('\[video=([a-z]+);([a-z0-9-_]+)\]', Regex::CASE_INSENSITIVE);
		}
		
		// use proper WCF 2 bbcode
		$replacements = [
			'[left]' => '[align=left]',
			'[/left]' => '[/align]',
			'[right]' => '[align=right]',
			'[/right]' => '[/align]',
			'[center]' => '[align=center]',
			'[/center]' => '[/align]',
			'[php]' => '[code=php]',
			'[/php]' => '[/code]',
			'[html]' => '[code=html]',
			'[/html]' => '[/code]',
			'[/video]' => '[/media]',
			'[attach=config]' => '[attach]'
		];
		$message = str_ireplace(array_keys($replacements), array_values($replacements), $message);
		
		// remove double quotes
		$message = preg_replace_callback('/\[[^\]]+"[^\]]*\]/', function ($matches) {
			return str_replace('"', '\'', $matches[0]);
		}, $message);
		
		// fix size bbcodes
		$message = preg_replace_callback('/\[size=\'?(\d+)\'?\]/i', function ($matches) {
			$size = 36;
			
			switch ($matches[1]) {
				case 1:
					$size = 8;
				break;
				case 2:
					$size = 10;
				break;
				case 3:
					$size = 12;
				break;
				case 4:
					$size = 14;
				break;
				case 5:
					$size = 18;
				break;
				case 6:
					$size = 24;
				break;
			}
			
			return '[size='.$size.']';
		}, $message);
		
		// quotes
		$message = $quoteRegex->replace($message, $quoteCallback);
		
		// media
		$message = $mediaRegex->replace($message, '[media]');
		
		$message = MessageUtil::stripCrap($message);
		
		return $message;
	}
	
	/**
	 * Converts vb's birthday format (mm-dd-yy)
	 * 
	 * @param       string          $birthday
	 * @return      string
	 */
	private static function convertBirthday($birthday) {
		$a = explode('-', $birthday);
		if (count($a) != 3) return '0000-00-00';
		
		return $a[2] . '-' . $a[0] . '-' . $a[1];
	}
}
