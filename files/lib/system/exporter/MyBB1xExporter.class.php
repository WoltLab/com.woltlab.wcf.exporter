<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wbb\data\board\BoardCache;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\data\user\rank\UserRank;
use wcf\data\user\UserProfile;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\database\DatabaseException;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Callback;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;

/**
 * Exporter for MyBB 1.x
 *
 * @author	Tim Duesterhus
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class MyBB1xExporter extends AbstractExporter {
	/**
	 * selected import data
	 * @var array
	 */
	protected $selectedData = array();
	
	/**
	 * board cache
	 * @var array
	 */
	protected $boardCache = array();
	
	/**
	 * @see wcf\system\exporter\AbstractExporter::$methods
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
		'com.woltlab.wcf.label' => 'Labels'
	);
	
	/**
	 * @see wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.user.follower' => 100
	);
	
	/**
	 * @see wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			/*'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.attachment',
				'com.woltlab.wcf.conversation.label'
			),*/
			'com.woltlab.wbb.board' => array(
				/*'com.woltlab.wbb.acl',*/
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'
			),
		);
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."awaitingactivation";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'inc/mybb_group.php')) return false;
		}
		
		return true;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateSelectedData()
	 */
	public function validateSelectedData(array $selectedData) {
		$this->selectedData = $selectedData;
		
		return true;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			if (in_array('com.woltlab.wcf.user.group', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.group';
				if (in_array('com.woltlab.wcf.user.rank', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.rank';
			}
			
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			/*if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
				$queue[] = 'com.woltlab.wcf.conversation.user';
			}*/
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			if (in_array('com.woltlab.wcf.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.label';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}
			if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';
		}
		
		return $queue;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'mybb_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usergroups
			WHERE	gID > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(2));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user groups.
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."usergroups
			WHERE		gid > ?
			ORDER BY	gid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(2));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['gid'], array(
				'groupName' => $row['title'],
				'groupType' => UserGroup::OTHER,
				'userOnlineMarking' => StringUtil::replace('{username}', '%s', $row['namestyle']),
				'showOnTeamPage' => $row['showforumteam'],
				'priority' => $row['disporder'] ? pow(2, 10 - $row['disporder']) : 0 // TODO: Do we what this?
			));
		}
	}

	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."users";
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
		$sql = "SELECT		user_table.*, activation_table.code AS activationCode, activation_table.type AS activationType,
					activation_table.misc AS newEmail, ban_table.reason AS banReason
			FROM		".$this->databasePrefix."users user_table
			LEFT JOIN	".$this->databasePrefix."awaitingactivation activation_table
			ON		user_table.uid = activation_table.uid
			LEFT JOIN	".$this->databasePrefix."banned ban_table
			ON			user_table.uid = ban_table.uid
					AND	ban_table.lifted <> ?
			ORDER BY	uid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		
		WCF::getDB()->beginTransaction();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['regdate'],
				'banned' => $row['banReason'] === null ? 0 : 1,
				'banReason' => $row['banReason'],
				($row['activationType'] == 'e' ? 're' : '').'activationCode' => $row['activationCode'] ? UserRegistrationUtil::getActivationCode() : 0, // mybb's codes are strings
				'newEmail' => $row['newEmail'] ?: '',
				'oldUsername' => '',
				'registrationIpAddress' => $row['regip'],
				'signature' => $row['signature'],
				'signatureEnableBBCodes' => 1,
				'signatureEnableHtml' => 0,
				'signatureEnableSmilies' => 1,
				'disableSignature' => $row['suspendsignature'],
				'disableSignatureReason' => '',
				'userTitle' => $row['usertitle'],
				'lastActivityTime' => $row['lastactive']
			);
			$additionalData = array(
				'groupIDs' => explode(',', $row['additionalgroups'].','.$row['usergroup']),
				'options' => array()
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['uid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('mybb1:'.$row['password'].':'.$row['salt'], $newUserID));
			}
		}
		WCF::getDB()->commitTransaction();
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usertitles";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$userTitleRow = $statement->fetchArray();
		
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usergroups
			WHERE		usertitle <> ?
				AND	gid <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('', 1));
		$userGroupsRow = $statement->fetchArray();
		
		return $userTitleRow['count'] + $userGroupsRow['count'];
	}
	
	/**
	 * Exports user ranks.
	 */
	public function exportUserRanks($offset, $limit) {
		$sql = "(
				SELECT		utid, 0 AS gid, posts, title, starimage, stars
				FROM		".$this->databasePrefix."usertitles
			)
			UNION
			(
				SELECT		0 AS utid, gid, 0 AS posts, usertitle AS title, starimage, stars
				FROM		".$this->databasePrefix."usergroups
				WHERE		usertitle <> ?
					AND	gid <> ?
			)
			ORDER BY	utid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('', 1));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['utid'], array(
				'groupID' => $row['gid'],
				'requiredPoints' => $row['posts'],
				'rankTitle' => $row['title'],
				'rankImage' => $row['starimage'],
				'repeatImage' => $row['stars'],
				'requiredGender' => 0 // neutral
			));
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."users
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
		$sql = "SELECT		uid, buddylist
			FROM		".$this->databasePrefix."users
			WHERE		buddylist <> ?
			ORDER BY	uid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(''));
		while ($row = $statement->fetchArray()) {
			$buddylist = array_unique(ArrayUtil::toIntegerArray(explode(',', $row['buddylist'])));
			
			foreach ($buddylist as $buddy) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
					'userID' => $row['uid'],
					'followUserID' => $buddy
				));
			}
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."users
			WHERE		avatar <> ?
				AND	avatartype IN (?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('', 'upload', 'gallery'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		uid, avatar, avatardimensions, avatartype
			FROM		".$this->databasePrefix."users
			WHERE		avatar <> ?
				AND	avatartype IN (?, ?)";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('', 'upload', 'gallery'));
		
		while ($row = $statement->fetchArray()) {
			$path = parse_url($row['avatar']);
			list($width, $height) = explode('|', $row['avatardimensions']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import(0, array(
				'avatarName' => basename($path['path']),
				'avatarExtension' => pathinfo($path['path'], PATHINFO_EXTENSION),
				'width' => $width,
				'height' => $height,
				'userID' => $row['uid']
			), array('fileLocation' => $this->fileSystemPath . $path['path']));
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		return $this->countUsers();
	}
	
	/**
	 * Exports conversation folders.
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		uid, pmfolders
			FROM		".$this->databasePrefix."users
			ORDER BY	folderID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$folders = explode('$%%$', $row['pmfolders']);
			foreach ($folders as $folder) {
				list($folderID, $folderName) = explode('**', $folder);
				if ($folderID <= 4) continue; // the first 4 folders are the default folders
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['uid'].'-'.$folderID, array(
					'userID' => $row['uid'],
					'label' => $folderName
				));
			}
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		// TODO: Find out how to group messages belonging together
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."forums";
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
			FROM		".$this->databasePrefix."forums
			ORDER BY	pid, disporder, fid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['pid']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['fid'], array(
				'parentID' => ($board['pid'] ?: null),
				'position' => $board['disporder'],
				'boardType' => ($board['linkto'] ? Board::TYPE_LINK : ($board['type'] == 'c' ? Board::TYPE_CATEGORY : Board::TYPE_BOARD)),
				'title' => $board['name'],
				'description' => $board['description'],
				'descriptionUseHtml' => 1, // cannot be disabled
				'externalURL' => $board['linkto'],
				'countUserPosts' => $board['usepostcounts'],
				'isClosed' => $board['open'] ? 0 : 1,
				'isInvisible' => $board['active'] ? 0 : 1,
				'posts' => $board['posts'],
				'threads' => $board['threads']
			));
			
			$this->exportBoardsRecursively($board['fid']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."threads";
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
			FROM		".$this->databasePrefix."threads";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['fid'],
				'topic' => $row['subject'],
				'time' => $row['dateline'],
				'userID' => $row['uid'],
				'username' => $row['username'],
				'views' => $row['views'],
				'isSticky' => $row['sticky'] ? 1 : 0,
				'isDisabled' => $row['visible'] ? 0 : 1,
				'isClosed' => $row['closed'] ? 1 : 0,
				'isDeleted' => $row['deletetime'] ? 1 : 0,
				'deleteTime' => $row['deletetime']
			);
			
			$additionalData = array();
			// TODO: Labels don't work fully yet
			if ($row['prefix']) $additionalData['labels'] = array($row['fid'].'-'.$row['prefix']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['tid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."posts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		post_table.*, user_table.username AS editor
			FROM		".$this->databasePrefix."posts post_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		user_table.uid = post_table.edituid
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['pid'], array(
				'threadID' => $row['tid'],
				'userID' => $row['uid'],
				'username' => $row['username'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['dateline'],
				'isDisabled' => $row['visible'] ? 0 : 1,
				'editorID' => ($row['edituid'] ?: null),
				'editor' => $row['editor'] ?: '',
				'lastEditTime' => $row['edittime'],
				'editCount' => $row['editor'] ? 1 : 0,
				'enableSmilies' => $row['smilieoff'] ? 0 : 1,
				'showSignature' => $row['includesig'],
				'ipAddress' => $row['ipaddress']
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."attachments";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array());
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."attachments
			ORDER BY	aid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post', 0));
		while ($row = $statement->fetchArray()) {
			// TODO: respect uploads/-setting of MyBB
			$fileLocation = $this->fileSystemPath.'uploads/'.$row['attachname'];
			
			if ($imageSize = getimagesize($fileLocation)) {
				$row['isImage'] = 1;
				$row['width'] = $imageSize[0];
				$row['height'] = $imageSize[1];
			}
			else {
				$row['isImage'] = $row['width'] = $row['height'] = 0;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['aid'], array(
				'objectID' => $row['pid'],
				'userID' => ($row['uid'] ?: null),
				'filename' => $row['filename'],
				'filesize' => $row['filesize'],
				'fileType' => $row['filetype'],
				'isImage' => $row['isImage'],
				'width' => $row['width'],
				'height' => $row['height'],
				'downloads' => $row['downloads'],
				'uploadTime' => $row['dateuploaded']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."threadsubscriptions";
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
			FROM		".$this->databasePrefix."threadsubscriptions
			ORDER BY	sid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import($row['sid'], array(
				'objectID' => $row['tid'],
				'userID' => $row['uid'],
				'notification' => $row['notification']
			));
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."polls";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		poll_table.*, thread_table.firstpost
			FROM		".$this->databasePrefix."polls poll_table
			LEFT JOIN	".$this->databasePrefix."threads thread_table
			ON		poll_table.tid = thread_table.tid
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pid'], array(
				'objectID' => $row['firstpost'],
				'question' => $row['question'],
				'time' => $row['dateline'],
				'endTime' => $row['timeout'] ? $row['dateline'] + $row['timeout'] * 86400 : 0,
				'isChangeable' => 0,
				'isPublic' => $row['public'],
				'maxVotes' => $row['multiple'] ? $row['numoptions'] : 1,
				'votes' => $row['numvotes']
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
		$sql = "SELECT		pid, options, votes
			FROM		".$this->databasePrefix."polls
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$options = explode('||~|~||', $row['options']);
			$votes = explode('||~|~||', $row['votes']);
			$i = 1;
			foreach ($options as $key => $option) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['pid'].'-'.$i, array(
					'pollID' => $row['pid'],
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
			FROM	".$this->databasePrefix."pollvotes";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."pollvotes
			ORDER BY	vid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import($row['vid'], array(
				'pollID' => $row['pid'],
				'optionID' => $row['pid'].'-'.$row['voteoption'],
				'userID' => $row['uid']
			));
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."reputation
			WHERE		pid <> ?
				AND	adduid <> ?
				AND	reputation <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0, 0, 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLikes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."reputation
			WHERE		pid <> ?
				AND	adduid <> ?
				AND	reputation <> ?
			ORDER BY	rid ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0, 0, 0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import($row['rid'], array(
				'objectID' => $row['pid'],
				'objectUserID' => ($row['uid'] ?: null),
				'userID' => $row['adduid'],
				'likeValue' => ($row['reputation'] > 0 ? Like::LIKE : Like::DISLIKE),
				'time' => $row['dateline']
			));
		}
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."threadprefixes";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports labels.
	 */
	public function exportLabels($offset, $limit) {
		$prefixMap = array();
		$boardIDs = array_keys(BoardCache::getInstance()->getBoards());
		
		$sql = "SELECT	*
			FROM	".$this->databasePrefix."threadprefixes";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$forums = array_unique(ArrayUtil::toIntegerArray(explode(',', $row['forums'])));
			// -1 = global
			if (in_array('-1', $forums)) $forums = $boardIDs;
			
			foreach ($forums as $forum) {
				if (!isset($prefixMap[$forum])) $prefixMap[$forum] = array();
				$prefixMap[$forum][$row['pid']] = $row['prefix'];
			}
		}
		
		// save prefixes
		if (!empty($prefixMap)) {
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
			
			foreach ($prefixMap as $forumID => $data) {
				// import label group
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($forumID, array(
					'groupName' => 'labelgroup'.$forumID
				), array('objects' => array($objectType->objectTypeID => array($forumID))));
				
				// import labels
				foreach ($data as $prefixID => $prefix) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($forumID.'-'.$prefixID, array(
						'groupID' => $forumID,
						'label' => $prefix
					));
				}
			}
		}
	}

	private static function fixBBCodes($message) {
		static $videoRegex = null;
		static $quoteRegex = null;
		static $quoteCallback = null;
		
		if ($videoRegex === null) {
			$videoRegex = new Regex('\[video=[a-z]+\]');
			$quoteRegex = new Regex('\[quote=\'(.*?)\' pid=\'(\d+)\' dateline=\'\d+\'\]');
			$quoteCallback = new Callback(function ($matches) {
				$username = StringUtil::replace(array("\\", "'"), array("\\\\", "\'"), $matches[1]);
				$postID = $matches[2];
				
				$postLink = LinkHandler::getInstance()->getLink('Thread', array(
					'application' => 'wbb',
					'postID' => $postID,
					'forceFrontend' => true
				)).'#post'.$postID;
				$postLink = StringUtil::replace(array("\\", "'"), array("\\\\", "\'"), $postLink);
				
				return "[quote='".$username."','".$postLink."']";
			});
		}
		
		// code bbcodes
		$message = StringUtil::replace('[php]', '[code=php]', $message);
		
		// media bbcodes
		$message = $videoRegex->replace($message, '[media]\\1');
		$message = StringUtil::replace('[/video]', '[/media]', $message);
		
		// quotes
		$message = $quoteRegex->replace($message, $quoteCallback);
	
		return $message;
	}
}
