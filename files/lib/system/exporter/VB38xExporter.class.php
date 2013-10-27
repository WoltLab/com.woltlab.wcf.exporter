<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wbb\data\board\BoardCache;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\database\DatabaseException;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Callback;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for vBulletin 3.8.x
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class VB38xExporter extends AbstractExporter {
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
	
	const ATTACHFILE_DATABASE = 0;
	const ATTACHFILE_FILESYSTEM = 1;
	const ATTACHFILE_FILESYSTEM_SUBFOLDER = 2;
	
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
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
		'com.woltlab.wcf.smiley' => 'Smilies',
		
		'com.woltlab.blog.category' => 'BlogCategories',
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments',
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.like' => 'BlogEntryLikes'
	);
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 100,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.conversation.attachment' => 100,
		'com.woltlab.wbb.thread' => 200,
		'com.woltlab.wbb.attachment' => 100,
		'com.woltlab.wbb.acl' => 50
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
			/*	'com.woltlab.wcf.user.option',*/
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
			/*	'com.woltlab.wbb.acl',*/
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.label'
			),
			'com.woltlab.wcf.smiley' => array()
		);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$templateversion = $this->readOption('templateversion');
		
		if (version_compare($templateversion, '3.8.0', '<')) throw new DatabaseException('Cannot import less than vB 3.8.x', $this->database);
		if (version_compare($templateversion, '4.0.0', '>=')) throw new DatabaseException('Cannot import greater than vB 3.x.x', $this->database);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'includes/version_vbulletin.php')) return false;
		}
		
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
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
			
		/*	if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';*/
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
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';
		
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
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."usergroup
			ORDER BY	usergroupid ASC";
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
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['usergroupid'], array(
				'groupName' => $row['title'],
				'groupDescription' => $row['description'],
				'groupType' => $groupType,
				'userOnlineMarking' => $row['opentag'].'%s'.$row['closetag']
			));
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."user";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		user_table.*, textfield.*, useractivation.type AS activationType, useractivation.emailchange, userban.liftdate, userban.reason AS banReason
			FROM		".$this->databasePrefix."user user_table
			LEFT JOIN	".$this->databasePrefix."usertextfield textfield
			ON		user_table.userid = textfield.userid
			LEFT JOIN	".$this->databasePrefix."useractivation useractivation
			ON		user_table.userid = useractivation.userid
			LEFT JOIN	".$this->databasePrefix."userban userban
			ON		user_table.userid = userban.userid
			ORDER BY	user_table.userid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['joindate'],
				'banned' => $row['liftdate'] !== null && $row['liftdate'] == 0 ? 1 : 0,
				'banReason' => $row['banReason'],
				'activationCode' => $row['activationType'] !== null && $row['activationType'] == 0 && $row['emailchange'] == 0 ? UserRegistrationUtil::getActivationCode() : 0, // vB's codes are strings
				'oldUsername' => '',
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['ipaddress']), // TODO: check whether this is the registration IP
				'signature' => $row['signature'],
				'userTitle' => ($row['customtitle'] != 0) ? $row['usertitle'] : '',
				'lastActivityTime' => $row['lastactivity']
			);
			$additionalData = array(
				'groupIDs' => explode(',', $row['membergroupids'].','.$row['usergroupid']),
				'options' => array()
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('vb3:'.$row['password'].':'.$row['salt'], $newUserID));
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
		$statement->execute(array(1, 2, ''));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user ranks.
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
			ORDER BY	usertitleid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(1, 2, ''));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['usertitleid'], array(
				'groupID' => $row['groupID'],
				'requiredPoints' => $row['minposts'] * 5,
				'rankTitle' => $row['title']
			));
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
		$statement->execute(array(''));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		userid, buddylist
			FROM		".$this->databasePrefix."usertextfield
			WHERE		buddylist <> ?
			ORDER BY	userid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(''));
		while ($row = $statement->fetchArray()) {
			$buddies = array_unique(ArrayUtil::toIntegerArray(explode(' ', $row['buddylist'])));
			foreach ($buddies as $buddy) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
					'userID' => $row['userid'],
					'followUserID' => $buddy
				));
			}
		}
	}
	
	/**
	 * Counts guestbook entries.
	 */
	public function countGuestbookEntries() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."visitormessage";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports guestbook entries.
	 */
	public function exportGuestbookEntries($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."visitormessage
			ORDER BY	vmid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['vmid'], array(
				'objectID' => $row['userid'],
				'userID' => $row['postuserid'],
				'username' => $row['postusername'],
				'message' => $row['pagetext'],
				'time' => $row['dateline']
			));
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
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		customavatar.*, user.avatarrevision
			FROM		".$this->databasePrefix."customavatar customavatar
			LEFT JOIN	".$this->databasePrefix."user user
			ON		user.userid = customavatar.userid
			ORDER BY	customavatar.userid ASC";
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
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['userid'], array(
					'avatarName' => $row['filename'],
					'avatarExtension' => pathinfo($row['filename'], PATHINFO_EXTENSION),
					'width' => $row['width'],
					'height' => $row['height'],
					'userID' => $row['userid']
				), array('fileLocation' => $file));
				
				if (!$this->readOption('usefileavatar')) unlink($file);
			}
			catch (\Exception $e) {
				if (!$this->readOption('usefileavatar') && $file) @unlink($file);
				
				throw $e;
			}
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usertextfield
			WHERE	pmfolders IS NOT NULL";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation folders.
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		userid, pmfolders
			FROM		".$this->databasePrefix."usertextfield
			WHERE		pmfolders IS NOT NULL
			ORDER BY	userid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$pmfolders = unserialize($row['pmfolders']);
			foreach ($pmfolders as $key => $val) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['userid'].'-'.$key, array(
					'userID' => $row['userid'],
					'label' => mb_substr($val, 0, 80)
				));
			}
		}
	}

	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."pm
			WHERE	parentpmid = ?
				OR pmid = parentpmid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		pm.*, text.*
			FROM		".$this->databasePrefix."pm pm
			LEFT JOIN	".$this->databasePrefix."pmtext text
			ON		pm.pmtextid = text.pmtextid
			WHERE			pm.parentpmid = ?
					OR	pm.pmid = pm.parentpmid
			ORDER BY	pm.pmid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['pmid'], array(
				'subject' => $row['title'],
				'time' => $row['dateline'],
				'userID' => $row['fromuserid'],
				'username' => $row['fromusername'],
				'isDraft' => 0
			));
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."pmtext";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		pmtext.*,
					(
					".$this->database->handleLimitParameter("SELECT IF(pm.parentpmid = 0, pm.pmid, pm.parentpmid) FROM ".$this->databasePrefix."pm pm WHERE pmtext.pmtextid = pm.pmtextid", 1)."
					) AS conversationID
			FROM		".$this->databasePrefix."pmtext pmtext
			ORDER BY	pmtext.pmtextid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['pmtextid'], array(
				'conversationID' => $row['conversationID'],
				'userID' => $row['fromuserid'],
				'username' => $row['fromusername'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['dateline'],
				'enableSmilies' => $row['allowsmilie'],
				'enableHtml' => 0,
				'enableBBCodes' => 1,
				'showSignature' => $row['showsignature']
			));
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
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		pm.*, user.username, pmtext.touserarray, pmtext.dateline
			FROM		".$this->databasePrefix."pm pm
			LEFT JOIN	".$this->databasePrefix."user user
			ON		pm.userid = user.userid
			LEFT JOIN	".$this->databasePrefix."pmtext pmtext
			ON		pmtext.pmtextid = pm.pmtextid
			ORDER BY	pm.pmid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$recipients = unserialize($row['touserarray']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => ($row['parentpmid'] ?: $row['pmid']),
				'participantID' => $row['userid'],
				'username' => $row['username'],
				'hideConversation' => 0, // there is no trash
				'isInvisible' => (isset($recipients['bcc']) && isset($recipients['bcc'][$row['userid']])) ? 1 : 0,
				'lastVisitTime' => $row['messageread'] ? $row['dateline'] : 0
			), array('labelIDs' => ($row['folderid'] > 0 ? array($row['userid'].'-'.$row['folderid']) : array())));
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
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."forum
			ORDER BY	forumid ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parentid']][] = $row;
		}
	
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = -1) {
		if (!isset($this->boardCache[$parentID])) return;
		$getDaysPrune = function ($value) {
			if ($value == -1) return 1000;
			
			$availableDaysPrune = array(1, 3, 7, 14, 30, 60, 100, 365);
			
			foreach ($availableDaysPrune as $daysPrune) {
				if ($value <= $daysPrune) return $daysPrune;
			}
			
			return 1000;
		};
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['forumid'], array(
				'parentID' => ($board['parentid'] != -1 ? $board['parentid'] : null),
				'position' => $board['displayorder'],
				'boardType' => ($board['link'] ? Board::TYPE_LINK : ($board['options'] & self::FORUMOPTIONS_CANCONTAINTHREADS ? Board::TYPE_BOARD : Board::TYPE_CATEGORY)),
				'title' => $board['title_clean'],
				'description' => $board['description_clean'],
				'descriptionUseHtml' => 0,
				'externalURL' => $board['link'],
				'countUserPosts' => $board['options'] & self::FORUMOPTIONS_COUNTPOSTS ? 1 : 0,
				'daysPrune' => $getDaysPrune($board['daysprune']),
				'enableMarkingAsDone' => 0,
				'ignorable' => 1,
				'isClosed' => $board['options'] & self::FORUMOPTIONS_ALLOWPOSTING ? 1 : 0,
				'isInvisible' => $board['options'] & self::FORUMOPTIONS_ACTIVE ? 0 : 1,
				'searchable' => $board['options'] & self::FORUMOPTIONS_INDEXPOSTS ? 1 : 0,
				'searchableForSimilarThreads' => $board['options'] & self::FORUMOPTIONS_INDEXPOSTS ? 1 : 0,
				'clicks' => 0,
				'posts' => $board['replycount'],
				'threads' => $board['threadcount']
			));
			
			$this->exportBoardsRecursively($board['forumid']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."thread";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."thread
			ORDER BY	threadid ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['forumid'],
				'topic' => $row['title'],
				'time' => $row['dateline'],
				'userID' => $row['postuserid'],
				'username' => $row['postusername'],
				'views' => $row['views'],
				'isAnnouncement' => 0,
				'isSticky' => $row['sticky'],
				'isDisabled' => $row['visible'] == 1 ? 0 : 1, // visible = 2 is deleted
				'isClosed' => $row['open'] == 1 ? 0 : 1, // open = 10 is redirect
				'isDeleted' => $row['visible'] == 2 ? 1 : 0,
				'movedThreadID' => ($row['open'] == 10 && $row['pollid'] ? $row['pollid'] : null), // target thread is saved in pollid...
				'movedTime' => 0,
				'isDone' => 0,
				'deleteTime' => TIME_NOW,
				'lastPostTime' => $row['lastpost']
			);
			$additionalData = array();
			if ($row['prefixid']) $additionalData['labels'] = array($row['prefixid']);
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
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."post";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports posts.
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
			ON		".$this->databasePrefix."postedithistory.postedithistoryid = (
						SELECT	MAX(postedithistoryid)
						FROM	".$this->databasePrefix."postedithistory postedithistory2
						WHERE	postedithistory2.postid = post.postid
					)
			ORDER BY	post.postid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['postid'], array(
				'threadID' => $row['threadid'],
				'userID' => $row['userid'],
				'username' => $row['username'],
				'subject' => $row['title'],
				'message' => self::fixBBCodes($row['pagetext']),
				'time' => $row['dateline'],
				'isDeleted' => $row['visible'] == 2 ? 1 : 0,
				'isDisabled' => $row['visible'] == 0 ? 1 : 0,
				'isClosed' => 0,
				'editorID' => ($row['editorID'] ?: null),
				'editor' => $row['editor'] ?: '',
				'lastEditTime' => $row['lastEditTime'] ?: 0,
				'editCount' => $row['editCount'] ?: 0,
				'editReason' => $row['editReason'] ?: '',
				'attachments' => $row['attach'],
				'enableSmilies' => $row['allowsmilie'],
				'enableHtml' => 0,
				'enableBBCodes' => 1,
				'showSignature' => $row['showsignature'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress'])
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."attachment";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."attachment
			ORDER BY	attachmentid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$file = null;
			
			try {
				switch ($this->readOption('attachfile')) {
					case self::ATTACHFILE_DATABASE:
						$file = FileUtil::getTemporaryFilename('attachment_');
						file_put_contents($file, $row['filedata']);
					break;
					case self::ATTACHFILE_FILESYSTEM:
						$file = FileUtil::addTrailingSlash($this->readOption('attachpath'));
						$file .= $row['userid'].'/'.$row['attachmentid'].'.attach';
					break;
					case self::ATTACHFILE_FILESYSTEM_SUBFOLDER:
						$file = FileUtil::addTrailingSlash($this->readOption('attachpath'));
						$file .= implode('/', str_split($row['userid'])).'/'.$row['attachmentid'].'.attach';
					break;
				}
				
				if ($imageSize = getimagesize($file)) {
					$row['isImage'] = 1;
					$row['width'] = $imageSize[0];
					$row['height'] = $imageSize[1];
				}
				else {
					$row['isImage'] = $row['width'] = $row['height'] = 0;
				}
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['attachmentid'], array(
					'objectID' => $row['postid'],
					'userID' => ($row['userid'] ?: null),
					'filename' => $row['filename'],
					'filesize' => $row['filesize'],
					'fileType' => FileUtil::getMimeType($file),
					'isImage' => $row['isImage'],
					'width' => $row['width'],
					'height' => $row['height'],
					'downloads' => $row['counter'],
					'uploadTime' => $row['dateline']
				), array('fileLocation' => $file));
				
				if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE) unlink($file);
			}
			catch (\Exception $e) {
				if ($this->readOption('attachfile') == self::ATTACHFILE_DATABASE && $file) @unlink($file);
			
				throw $e;
			}
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."subscribethread";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."subscribethread
			ORDER BY	subscribethreadid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import($row['subscribethreadid'], array(
				'objectID' => $row['threadid'],
				'userID' => $row['userid']
			));
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."poll";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		poll.*, thread.firstpostid
			FROM		".$this->databasePrefix."poll poll
			LEFT JOIN	".$this->databasePrefix."thread thread
			ON			poll.pollid = thread.pollid
					AND	thread.open <> ?
			ORDER BY	poll.pollid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(10));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollid'], array(
				'objectID' => $row['firstpostid'],
				'question' => $row['question'],
				'time' => $row['dateline'],
				'endTime' => $row['dateline'] + $row['timeout'] * 86400,
				'isChangeable' => 0,
				'isPublic' => $row['public'] ? 1 : 0,
				'sortByVotes' => 0,
				'maxVotes' => $row['multiple'] ? $row['numberoptions'] : 1,
				'votes' => $row['voters']
			));
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
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."poll
			ORDER BY	pollid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$options = explode('|||', $row['options']);
			$votes = explode('|||', $row['votes']);
			
			$i = 1;
			foreach ($options as $key => $option) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pollid'].' '.$i, array(
					'pollID' => $row['pollid'],
					'optionValue' => $option,
					'showOrder' => $i,
					'votes' => $votes[$key]
				));
				
				$i++;
			}
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."pollvote";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."pollvote
			ORDER BY	pollvoteid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, array(
				'pollID' => $row['pollid'],
				'optionID' => $row['pollid'].'-'.$row['voteoption'],
				'userID' => $row['userid']
			));
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."reputation";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLikes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."reputation
			ORDER BY	reputationid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, array(
				'objectID' => $row['postid'],
				'objectUserID' => ($row['userid'] ?: null),
				'userID' => $row['whoadded'],
				'likeValue' => ($row['reputation'] > 0 ? Like::LIKE : Like::DISLIKE)
			));
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
	 */
	public function exportLabels($offset, $limit) {
		if (!$offset) {
			$boardIDs = array_keys(BoardCache::getInstance()->getBoards());
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
			
			$sql = "SELECT		*
				FROM		".$this->databasePrefix."prefixset
				ORDER BY	prefixsetid ASC";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute();
			
			while ($row = $statement->fetchArray()) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($row['prefixsetid'], array(
					'groupName' => $row['prefixsetid']
				), array('objects' => array($objectType->objectTypeID => $boardIDs)));
			}
		}
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."prefix
			ORDER BY	prefixid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($row['prefixid'], array(
				'groupID' => $row['prefixsetid'],
				'label' => mb_substr($row['prefixid'], 0, 80)
			));
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
	 */
	public function exportSmilies($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."smilie
			ORDER BY	smilieid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array());
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . $row['smiliepath'];
	
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smilieid'], array(
				'smileyTitle' => $row['title'],
				'smileyCode' => $row['smilietext'],
				'showOrder' => $row['displayorder']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private function readOption($optionName) {
		static $optionCache = array();
		
		if (!isset($optionCache[$optionName])) {
			$sql = "SELECT	value
				FROM	".$this->databasePrefix."setting
				WHERE	varname = ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array($optionName));
			$row = $statement->fetchArray();
			
			$optionCache[$optionName] = $row['value'];
		}
		
		return $optionCache[$optionName];
	}
	
	private static function fixBBCodes($message) {
		static $quoteRegex = null;
		static $quoteCallback = null;
		
		if ($quoteRegex === null) {
			$quoteRegex = new Regex('\[quote=(.*?);(\d+)\]', Regex::CASE_INSENSITIVE);
			$quoteCallback = new Callback(function ($matches) {
				$username = str_replace(array("\\", "'"), array("\\\\", "\'"), $matches[1]);
				$postID = $matches[2];
				
				$postLink = LinkHandler::getInstance()->getLink('Thread', array(
						'application' => 'wbb',
						'postID' => $postID,
						'forceFrontend' => true
				)).'#post'.$postID;
				$postLink = str_replace(array("\\", "'"), array("\\\\", "\'"), $postLink);
				
				return "[quote='".$username."','".$postLink."']";
			});
		}
		
		// use proper WCF 2 bbcode
		$replacements = array(
			'[left]' => '[align=left]',
			'[/left]' => '[/align]',
			'[right]' => '[align=right]',
			'[/right]' => '[/align]',
			'[center]' => '[align=center]',
			'[/center]' => '[/align]',
			'[php]' => '[code=php]',
			'[/php]' => '[/code]',
			'[html]' => '[code=html]',
			'[/html]' => '[/code]'
		);
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
		
		$message = MessageUtil::stripCrap($message);
		
		return $message;
	}
}
