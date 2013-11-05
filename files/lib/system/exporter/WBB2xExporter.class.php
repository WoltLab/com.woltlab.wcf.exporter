<?php
namespace wcf\system\exporter;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;

/**
 * Exporter for Burning Board 2.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class WBB2xExporter extends AbstractExporter {
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
		'com.woltlab.wcf.user.avatar' => 'UserAvatars',
		'com.woltlab.wcf.user.option' => 'UserOptions',
		'com.woltlab.wcf.conversation.label' => 'ConversationFolders',
		'com.woltlab.wcf.conversation' => 'Conversations',
		'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
		'com.woltlab.wcf.conversation.attachment' => 'ConversationAttachments',
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
		'com.woltlab.wbb.attachment' => 'PostAttachments',
		'com.woltlab.wbb.watchedThread' => 'WatchedThreads',
		'com.woltlab.wbb.poll' => 'Polls',
		'com.woltlab.wbb.poll.option' => 'PollOptions',
		'com.woltlab.wcf.label' => 'Labels',
		'com.woltlab.wbb.acl' => 'ACLs',
		'com.woltlab.wcf.smiley' => 'Smilies'
	);
	
	protected $permissionMap = array(
		'can_view_board' => 'canViewBoard',
		'can_enter_board' => 'canEnterBoard',
		'can_read_thread' => 'canReadThread',
		'can_start_topic' => 'canStartThread',
		'can_reply_topic' => 'canReplyThread',
		'can_reply_own_topic' => 'canReplyOwnThread',
		'can_post_poll' => 'canStartPoll',
		'can_upload_attachments' => 'canUploadAttachment',
		'can_download_attachments' => 'canDownloadAttachment',
		'can_post_without_moderation' => 'canReplyThreadWithoutModeration',
		//'can_close_own_topic' => '',
		//'can_use_search' => '',
		'can_vote_poll' => 'canVotePoll',
		//'can_rate_thread' => '',
		'can_del_own_post' => 'canDeleteOwnPost',
		'can_edit_own_post' => 'canEditOwnPost',
		//'can_del_own_topic' => '',
		//'can_edit_own_topic' => '',
		//'can_move_own_topic' => '',
		//'can_use_post_html' => '',
		//'can_use_post_bbcode' => '',
		//'can_use_post_smilies' => '',
		//'can_use_post_icons' => '',
		//'can_use_post_images' => '',
		//'can_use_prefix' => ''
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."posts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'newthread.php')) return false;
		}
		
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wcf.label'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.attachment',
				'com.woltlab.wcf.conversation.label'
			),
			'com.woltlab.wcf.smiley' => array()
		);
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
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
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
			}
		}
		
		// smiley
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';
		
		return $queue;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'bb1_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."groups";
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
			FROM		".$this->databasePrefix."groups
			ORDER BY	groupid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$groupType = 4;
			switch ($row['grouptype']) {
				case 1: // guests
					$groupType = UserGroup::GUESTS;
					break;
						
				case 4: // users
					$groupType = UserGroup::USERS;
					break;
				case 5: // open group
				case 6: // moderated group
					$groupType = $row['grouptype'];
					break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['groupid'], array(
				'groupName' => $row['title'],
				'groupType' => $groupType,
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
		// cache profile fields
		$profileFields = array();
		$sql = "SELECT	profilefieldid
			FROM	".$this->databasePrefix."profilefields
			WHERE	profilefieldid > 3";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$profileFields[] = $row['profilefieldid'];
		}
		
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		userfields.*, user.*,
					(
						SELECT	GROUP_CONCAT(groupid)
						FROM	".$this->databasePrefix."user2groups
						WHERE	userid = user.userid
					) AS groupIDs
			FROM		".$this->databasePrefix."users user
			LEFT JOIN	".$this->databasePrefix."userfields userfields
			ON		(userfields.userid = user.userid)
			ORDER BY	user.userid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['regdate'],
				'signature' => self::fixBBCodes($row['signature']),
				'lastActivityTime' => $row['lastactivity'],
				'userTitle' => $row['title'],
				'disableSignature' => $row['disablesignature'],
				'banned' => $row['blocked'],
				'signatureEnableSmilies' => $row['allowsigsmilies'],
				'signatureEnableHtml' => $row['allowsightml'],
				'signatureEnableBBCodes' => $row['allowsigbbcode'],
				'registrationIpAddress' => $row['reg_ipaddress']
			);
			
			$options = array(
				'birthday' => $row['birthday'],
				'gender' => $row['gender'],
				'homepage' => $row['homepage'],
				'icq' => ($row['icq'] ? $row['icq'] : ''),
				'location' => (!empty($row['field1']) ? $row['field1'] : ''),
				'hobbies' => (!empty($row['field2']) ? $row['field2'] : ''),
				'occupation' => (!empty($row['field3']) ? $row['field3'] : ''),
			);
			
			foreach ($profileFields as $profileFieldID) {
				if (!empty($row['field'.$profileFieldID])) {
					$options[$profileFieldID] = $row['field'.$profileFieldID];
				}
			}
			
			$additionalData = array(
				'groupIDs' => explode(',', $row['groupIDs']),
				'options' => $options
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userid'], $data, $additionalData);
				
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('wbb2:'.(!empty($row['sha1_password']) ? $row['sha1_password'] : $row['password']), $newUserID));
			}
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."ranks";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user ranks.
	 */
	public function exportUserRanks($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."ranks
			ORDER BY	rankid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['rankid'], array(
				'groupID' => $row['groupid'],
				'requiredPoints' => $row['needposts'] * 5,
				'rankTitle' => $row['ranktitle'],
				'requiredGender' => $row['gender']
			));
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."avatars
			WHERE	userid <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."avatars
			WHERE		userid <> ?
			ORDER BY	avatarid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['avatarid'], array(
				'avatarName' => $row['avatarname'],
				'avatarExtension' => $row['avatarextension'],
				'width' => $row['width'],
				'height' => $row['height'],
				'userID' => $row['userid']
			), array('fileLocation' => $this->fileSystemPath . 'images/avatars/avatar-' . $row['avatarid'] . '.' . $row['avatarextension']));
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profilefields
			WHERE	profilefieldid > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(3));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user options.
	 */
	public function exportUserOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."profilefields
			WHERE		profilefieldid > ?
			ORDER BY	profilefieldid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(3));
		while ($row = $statement->fetchArray()) {
			$optionType = 'text';
			switch ($row['fieldtype']) {
				case 'select':
					$optionType = 'select';
					break;
				case 'multiselect':
					$optionType = 'multiSelect';
					break;
				case 'checkbox':
					$optionType = 'boolean';
					break;
				case 'date':
					$optionType = 'date';
					break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['profilefieldid'], array(
				'categoryName' => 'profile.personal',
				'optionType' => $optionType,
				'required' => $row['required'],
				'visible' => ($row['hidden'] ? 0 : UserOption::VISIBILITY_ALL),
				'showOrder' => $row['fieldorder'],
				'selectOptions' => $row['fieldoptions'],
				'editable' => UserOption::EDITABILITY_ALL
			), array('name' => $row['title']));
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."folders";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation folders.
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."folders
			ORDER BY	folderid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['folderid'], array(
				'userID' => $row['userid'],
				'label' => mb_substr($row['title'], 0, 80)
			));
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."privatemessage";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		pm.*, user_table.username
			FROM		".$this->databasePrefix."privatemessage pm
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		(user_table.userid = pm.senderid)
			ORDER BY	pm.privatemessageid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$conversationID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['privatemessageid'], array(
				'subject' => $row['subject'],
				'time' => $row['sendtime'],
				'userID' => $row['senderid'],
				'username' => ($row['username'] ?: '')
			));
			
			// import message
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['privatemessageid'], array(
				'conversationID' => $row['privatemessageid'],
				'userID' => $row['senderid'],
				'username' => ($row['username'] ?: ''),
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['sendtime'],
				'attachments' => $row['attachments'],
				'enableSmilies' => $row['allowsmilies'],
				'enableHtml' => $row['allowhtml'],
				'enableBBCodes' => $row['allowbbcode'],
				'showSignature' => $row['showsignature']
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."privatemessagereceipts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."privatemessagereceipts
			ORDER BY	privatemessageid DESC, recipientid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => $row['privatemessageid'],
				'participantID' => $row['recipientid'],
				'username' => $row['recipient'],
				'hideConversation' => $row['deletepm'],
				'isInvisible' => $row['blindcopy'],
				'lastVisitTime' => $row['view']
			), array('labelIDs' => ($row['folderid'] ? array($row['folderid']) : array())));
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		return $this->countAttachments('privatemessageid');
	}
	
	/**
	 * Exports conversation attachments.
	 */
	public function exportConversationAttachments($offset, $limit) {
		$this->exportAttachments('privatemessageid', 'com.woltlab.wcf.conversation.attachment', $offset, $limit);
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."boards";
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
			FROM		".$this->databasePrefix."boards
			ORDER BY	parentid, boardorder";
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
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['boardid'], array(
				'parentID' => ($board['parentid'] ?: null),
				'position' => $board['boardorder'],
				'boardType' => (!$board['isboard'] ? 1 : (!empty($board['externalurl']) ? 2 : 0)),
				'title' => $board['title'],
				'description' => $board['description'],
				'externalURL' => $board['externalurl'],
				'countUserPosts' => $board['countuserposts'],
				'isClosed' => $board['closed'],
				'isInvisible' => intval($board['invisible'] == 2)
			));
			
			$this->exportBoardsRecursively($board['boardid']);
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
		// get global prefixes
		$globalPrefixes = '';
		$sql = "SELECT	value
			FROM	".$this->databasePrefix."options
			WHERE	varname = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('default_prefix'));
		$row = $statement->fetchArray();
		if ($row !== false) $globalPrefixes = $row['value'];
		
		// get boards
		$boardPrefixes = array();
		
		$sql = "SELECT	boardid, prefix, prefixuse
			FROM	".$this->databasePrefix."boards
			WHERE	prefixuse > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$prefixes = '';
			
			switch ($row['prefixuse']) {
				case 1:
					$prefixes = $globalPrefixes;
					break;
				case 2:
					$prefixes = $globalPrefixes . "\n" . $row['prefix'];
					break;
				case 3:
					$prefixes = $row['prefix'];
					break;
			}
				
			$prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
			if ($prefixes) {
				$key = StringUtil::getHash($prefixes);
				$boardPrefixes[$row['boardid']] = $key;
			}
		}
		
		// get thread ids
		$threadIDs = $announcementIDs = array();
		$sql = "SELECT		threadid, important
			FROM		".$this->databasePrefix."threads
			ORDER BY	threadid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$threadIDs[] = $row['threadid'];
			if ($row['important'] == 2) $announcementIDs[] = $row['threadid'];
		}
		
		// get assigned boards (for announcements)
		$assignedBoards = array();
		if (!empty($announcementIDs)) {
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('threadid IN (?)', array($announcementIDs));
				
			$sql = "SELECT		boardid, threadid
				FROM		".$this->databasePrefix."announcements
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				if (!isset($assignedBoards[$row['threadid']])) $assignedBoards[$row['threadid']] = array();
				$assignedBoards[$row['threadid']][] = $row['boardid'];
			}
		}
		
		// get threads
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('threadid IN (?)', array($threadIDs));
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."threads
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['boardid'],
				'topic' => $row['topic'],
				'time' => $row['starttime'],
				'userID' => $row['starterid'],
				'username' => $row['starter'],
				'views' => $row['views'],
				'isAnnouncement' => intval($row['important'] == 2),
				'isSticky' => intval($row['important'] == 1),
				'isDisabled' => intval(!$row['visible']),
				'isClosed' => intval($row['closed'] == 1),
				'movedThreadID' => ($row['closed'] == 3 ? $row['pollid'] : null),
				'lastPostTime' => $row['lastposttime']
			);
			$additionalData = array();
			if (!empty($assignedBoards[$row['threadid']])) $additionalData['assignedBoards'] = $assignedBoards[$row['threadid']];
			if ($row['prefix'] && isset($boardPrefixes[$row['boardid']])) $additionalData['labels'] = array($boardPrefixes[$row['boardid']].'-'.$row['prefix']);
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['threadid'], $data, $additionalData);
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
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."posts
			ORDER BY	postid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['postid'], array(
				'threadID' => $row['threadid'],
				'userID' => $row['userid'],
				'username' => $row['username'],
				'subject' => $row['posttopic'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['posttime'],
				'isDisabled' => intval(!$row['visible']),
				'editorID' => ($row['editorid'] ?: null),
				'editor' => $row['editor'],
				'lastEditTime' => $row['edittime'],
				'editCount' => $row['editcount'],
				'attachments' => $row['attachments'],
				'enableSmilies' => $row['allowsmilies'],
				'enableHtml' => $row['allowhtml'],
				'enableBBCodes' => $row['allowbbcode'],
				'showSignature' => $row['showsignature'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress'])				
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments('postid');
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		$this->exportAttachments('postid', 'com.woltlab.wbb.attachment', $offset, $limit);
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."subscribethreads";
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
			FROM		".$this->databasePrefix."subscribethreads
			ORDER BY	userid, threadid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
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
			FROM	".$this->databasePrefix."polls";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('post'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		// prepare statements
		$sql = "SELECT		postid
			FROM		".$this->databasePrefix."posts
			WHERE		threadid = ?
			ORDER BY	posttime";
		$firstPostStatement = $this->database->prepareStatement($sql, 1);
		$sql = "SELECT		COUNT(*) AS votes
			FROM		".$this->databasePrefix."votes
			WHERE		id = ?
					AND votemode = 1";
		$votesStatement = $this->database->prepareStatement($sql);
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."polls poll
			ORDER BY	pollid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post'));
		while ($row = $statement->fetchArray()) {
			$postID = null;
			$votes = 0;
			
			// get first post id
			$firstPostStatement->execute(array($row['threadid']));
			$row2 = $firstPostStatement->fetchArray();
			if (empty($row2['postid'])) continue;
			$postID = $row2['postid'];
			
			// get votes
			$votesStatement->execute(array($row['pollid']));
			$row2 = $votesStatement->fetchArray();
			if (!empty($row2['votes'])) $votes = $row2['votes'];
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['pollid'], array(
				'objectID' => $postID,
				'question' => $row['question'],
				'time' => $row['starttime'],
				'endTime' => ($row['timeout'] ? $row['starttime'] + $row['timeout'] * 86400 : 0),
				'maxVotes' => $row['choicecount'],
				'votes' => $votes
			));
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."polloptions";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array());
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll options.
	 */
	public function exportPollOptions($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."polloptions
			ORDER BY	polloptionid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array());
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['polloptionid'], array(
			'pollID' => $row['pollid'],
			'optionValue' => $row['polloption'],
			'votes' => $row['votes']
			));
		}
	}
	
	/**
	 * Counts labels.
	 */
	public function countLabels() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."boards
			WHERE	prefixuse > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports labels.
	 */
	public function exportLabels($offset, $limit) {
		$prefixMap = array();
		
		// get global prefixes
		$globalPrefixes = '';
		$sql = "SELECT	value
			FROM	".$this->databasePrefix."options
			WHERE	varname = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('default_prefix'));
		$row = $statement->fetchArray();
		if ($row !== false) $globalPrefixes = $row['value'];
		
		// get boards
		$sql = "SELECT	boardid, prefix, prefixuse
			FROM	".$this->databasePrefix."boards
			WHERE	prefixuse > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$prefixes = '';
				
			switch ($row['prefixuse']) {
				case 1:
					$prefixes = $globalPrefixes;
					break;
				case 2:
					$prefixes = $globalPrefixes . "\n" . $row['prefix'];
					break;
				case 3:
					$prefixes = $row['prefix'];
					break;
			}
				
			$prefixes = StringUtil::trim(StringUtil::unifyNewlines($prefixes));
			if ($prefixes) {
				$key = StringUtil::getHash($prefixes);
				if (!isset($prefixMap[$key])) {
					$prefixMap[$key] = array(
						'prefixes' => $prefixes,
						'boardIDs' => array()
					);
				}
				
				$boardID = ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $row['boardid']);
				if ($boardID) $prefixMap[$key]['boardIDs'][] = $boardID;
			}
		}
		
		// save prefixes
		if (!empty($prefixMap)) {
			$i = 1;
			$objectType = ObjectTypeCache::getInstance()->getObjectTypeByName('com.woltlab.wcf.label.objectType', 'com.woltlab.wbb.board');
				
			foreach ($prefixMap as $key => $data) {
				// import label group
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label.group')->import($key, array(
					'groupName' => 'labelgroup'.$i
				), array('objects' => array($objectType->objectTypeID => $data['boardIDs'])));
				
				// import labels
				$labels = explode("\n", $data['prefixes']);
				foreach ($labels as $label) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.label')->import($key.'-'.$label, array(
						'groupID' => $key,
						'label' => mb_substr($label, 0, 80)
					));
				}
				
				$i++;
			}
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."permissions";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports ACLs.
	 */
	public function exportACLs($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."permissions
			ORDER BY	boardid, groupid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'objectID' => $row['boardid'],
				'groupID' => $row['groupid']
			);
			unset($row['boardid'], $row['groupid']);
			
			foreach ($row as $permission => $value) {
				if ($value == -1) continue;
				if (!isset($this->permissionMap[$permission])) continue;
				
				ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array_merge($data, array('optionValue' => $value)), array('optionName' => $this->permissionMap[$permission]));
			}
		}
	}
	
	/**
	 * Counts smilies.
	 */
	public function countSmilies() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."smilies";
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
			FROM		".$this->databasePrefix."smilies
			ORDER BY	smilieid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			// replace imagefolder
			$row['smiliepath'] = str_replace('{imagefolder}', 'images', $row['smiliepath']);
			
			// insert source path
			if (!FileUtil::isURL($row['smiliepath'])) {
				$row['smiliepath'] = $this->fileSystemPath.$row['smiliepath'];
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smilieid'], array(
				'smileyTitle' => $row['smilietitle'],
				'smileyCode' => $row['smiliecode'],
				'showOrder' => $row['smilieorder']
			), array('fileLocation' => $row['smiliepath']));
		}
	}
	
	private function countAttachments($indexName) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."attachments
			WHERE	".$indexName." > ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	private function exportAttachments($indexName, $objectType, $offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."attachments
			WHERE		".$indexName." > ?
			ORDER BY	attachmentid DESC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'attachments/attachment-'.$row['attachmentid'].'.'.$row['attachmentextension'];
			if (!@file_exists($fileLocation)) continue;
			
			$fileType = FileUtil::getMimeType($fileLocation);
			$isImage = 0;
			if ($fileType == 'image/jpeg' || $fileType == 'image/png' || $fileType == 'image/gif') $isImage = 1;
			
			ImportHandler::getInstance()->getImporter($objectType)->import($row['attachmentid'], array(
				'objectID' => $row[$indexName],
				'userID' => ($row['userid'] ?: null),
				'filename' => $row['attachmentname'].'.'.$row['attachmentextension'],
				'filesize' => $row['attachmentsize'],
				'fileType' => $fileType,
				'isImage' => $isImage,
				'downloads' => $row['counter'],
				'uploadTime' => $row['uploadtime'],
				'showOrder' => 0
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private static function fixBBCodes($text) {
		$text = str_ireplace('[center]', '[align=center]', $text);
		$text = str_ireplace('[/center]', '[/align]', $text);
		
		// remove crap
		$text = MessageUtil::stripCrap($text);
		
		return $text;
	}
}
