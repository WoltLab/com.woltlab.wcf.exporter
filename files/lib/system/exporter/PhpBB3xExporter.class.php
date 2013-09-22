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
use wcf\util\FileUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;
use wcf\util\UserRegistrationUtil;

/**
 * Exporter for phpBB 3x.x
 *
 * @author	Tim Duesterhus
 * @copyright	2001-2013 WoltLab GmbH
 * @license	WoltLab Burning Board License <http://www.woltlab.com/products/burning_board/license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework (commercial)
 */
class PhpBB3xExporter extends AbstractExporter {
	const TOPIC_TYPE_GLOBAL = 3;
	const TOPIC_TYPE_ANNOUCEMENT = 2;
	const TOPIC_TYPE_STICKY = 1;
	const TOPIC_TYPE_DEFAULT = 0;
	
	const TOPIC_STATUS_LINK = 2;
	const TOPIC_STATUS_CLOSED = 1;
	const TOPIC_STATUS_DEFAULT = 0;
	
	const USER_TYPE_USER_IGNORE = 2;
	
	const AVATAR_TYPE_GALLERY = 3;
	const AVATAR_TYPE_REMOTE = 2;
	const AVATAR_TYPE_UPLOADED = 1;
	const AVATAR_TYPE_NO_AVATAR = 0;
	
	const BOARD_TYPE_LINK = 2;
	const BOARD_TYPE_BOARD = 1;
	const BOARD_TYPE_CATEGORY = 0;
	
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
		'com.woltlab.wcf.label' => 'Labels',
		'com.woltlab.wbb.acl' => 'ACLs',
		'com.woltlab.wcf.smiley' => 'Smilies'
	);
	
	/**
	 * @see wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.conversation.attachment' => 100,
		'com.woltlab.wbb.thread' => 200,
		'com.woltlab.wbb.attachment' => 100,
		'com.woltlab.wbb.acl' => 1
	);
	
	/**
	 * @see wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
			/*	'com.woltlab.wcf.user.option',*/
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.attachment',
				'com.woltlab.wcf.conversation.label'
			),
			'com.woltlab.wcf.smiley' => array()
		);
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."zebra";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'includes/error_collector.php')) return false;
		}
		
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
			/*if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';*/
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
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
		}
		
		// smiley
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';
		
		return $queue;
	}
	
	/**
	 * @see wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'phpbb_';
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
			ORDER BY	group_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			switch ($row['group_id']) {
				case 1:
					$groupType = UserGroup::GUESTS;
				break;
				case 2:
					$groupType = UserGroup::USERS;
				break;
				case 6:
					// BOTS
					continue;
				break;
				default:
					$groupType = UserGroup::OTHER;
				break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['group_id'], array(
				'groupName' => $row['group_name'],
				'groupType' => $groupType,
				'userOnlineMarking' => ($row['group_colour'] ? '<span style="color: #'.$row['group_colour'].'">%s</span>' : '%s'),
				'showOnTeamPage' => $row['group_legend']
			));
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."users
			WHERE	user_type <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(self::USER_TYPE_USER_IGNORE));
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
		$sql = "SELECT		user_table.*, ban_table.ban_give_reason AS banReason,
					(
						SELECT	GROUP_CONCAT(group_id)
						FROM	".$this->databasePrefix."user_group
						WHERE	user_id = user_table.user_id
					) AS groupIDs
			FROM		".$this->databasePrefix."users user_table
			LEFT JOIN	".$this->databasePrefix."banlist ban_table
			ON			user_table.user_id = ban_table.ban_userid
					AND	ban_table.ban_end = ?
			WHERE		user_type <> ?
			ORDER BY	user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0, 2));
	
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['user_email'],
				'registrationDate' => $row['user_regdate'],
				'banned' => $row['banReason'] === null ? 0 : 1,
				'banReason' => $row['banReason'],
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['user_ip']),
				'signature' => self::fixBBCodes(StringUtil::decodeHTML($row['user_sig']), $row['user_sig_bbcode_uid']),
				'signatureEnableBBCodes' => ($row['user_sig_bbcode_uid'] ? (mb_strpos($row['user_sig'], $row['user_sig_bbcode_uid']) !== false ? 1 : 0) : 1),
				'signatureEnableHtml' => 0,
				'signatureEnableSmilies' => preg_match('/<!-- s.*? -->/', $row['user_sig']),
				'lastActivityTime' => $row['user_lastvisit']
			);
			$additionalData = array(
				'groupIDs' => explode(',', $row['groupIDs']),
				'options' => array()
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['user_id'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('phpbb3:'.$row['user_password'].':', $newUserID));
			}
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."ranks
			WHERE	rank_special = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user ranks.
	 */
	public function exportUserRanks($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."ranks
			WHERE		rank_special = ?
			ORDER BY	rank_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['rank_id'], array(
				'groupID' => 2, // 2 = registered users
				'requiredPoints' => $row['rank_min'] * 5,
				'rankTitle' => $row['rank_title'],
				'rankImage' => $row['rank_image'],
				'repeatImage' => 0,
				'requiredGender' => 0 // neutral
			));
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."zebra
			WHERE		friend = ?
				AND	foe = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(1, 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."zebra
			WHERE			friend = ?
					AND	foe = ?
			ORDER BY	user_id ASC, zebra_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(1, 0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
				'userID' => $row['user_id'],
				'followUserID' => $row['zebra_id']
			));
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."users
			WHERE	user_avatar_type IN (?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(self::AVATAR_TYPE_GALLERY, self::AVATAR_TYPE_UPLOADED));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		static $avatar_salt = null, $avatar_path = null, $avatar_gallery_path = null;
		if ($avatar_salt === null) {
			$sql = "SELECT	config_name, config_value
				FROM	".$this->databasePrefix."config
				WHERE	config_name IN (?, ?, ?)";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array('avatar_path', 'avatar_salt', 'avatar_gallery_path'));
			while ($row = $statement->fetchArray()) {
				$$row['config_name'] = $row['config_value'];
			}
		}
		
		$sql = "SELECT		user_id, user_avatar, user_avatar_type, user_avatar_width, user_avatar_height
			FROM		".$this->databasePrefix."users
			WHERE		user_avatar_type IN (?, ?)
			ORDER BY	user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(self::AVATAR_TYPE_GALLERY, self::AVATAR_TYPE_UPLOADED));
		while ($row = $statement->fetchArray()) {
			$extension = pathinfo($row['user_avatar'], PATHINFO_EXTENSION);
			switch ($row['user_avatar_type']) {
				case self::AVATAR_TYPE_UPLOADED:
					$location = FileUtil::addTrailingSlash($this->fileSystemPath.$avatar_path).$avatar_salt.'_'.intval($row['user_avatar']).'.'.$extension;
				break;
				case self::AVATAR_TYPE_GALLERY:
					$location = FileUtil::addTrailingSlash($this->fileSystemPath.$avatar_gallery_path).$row['user_avatar'];
				break;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import(0, array(
				'avatarName' => basename($row['user_avatar']),
				'avatarExtension' => $extension,
				'userID' => $row['user_id']
			), array('fileLocation' => $location));
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."privmsgs_folder";
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
			FROM		".$this->databasePrefix."privmsgs_folder
			ORDER BY	folder_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['folder_id'], array(
				'userID' => $row['user_id'],
				'label' => mb_substr($row['folder_name'], 0, 80)
			));
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."privmsgs
			WHERE	root_level = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "INSERT IGNORE INTO	wcf".WCF_N."_conversation_to_user
						(conversationID, participantID, hideConversation, isInvisible, lastVisitTime)
			VALUES			(?, ?, ?, ?, ?)";
		$insertStatement = WCF::getDB()->prepareStatement($sql);
		
		$sql = "(
				SELECT		msg_table.msg_id,
						msg_table.message_subject,
						msg_table.message_time,
						msg_table.author_id,
						0 AS isDraft,
						user_table.username
				FROM		".$this->databasePrefix."privmsgs msg_table
				LEFT JOIN	".$this->databasePrefix."users user_table
				ON		msg_table.author_id = user_table.user_id
				WHERE		root_level = ?
			)
			UNION
			(
				SELECT		draft_table.draft_id AS msg_id,
						draft_table.draft_subject AS message_subject,
						draft_table.save_time AS message_time,
						draft_table.user_id AS author_id,
						1 AS isDraft,
						user_table.username
				FROM		".$this->databasePrefix."drafts draft_table
				LEFT JOIN	".$this->databasePrefix."users user_table
				ON		draft_table.user_id = user_table.user_id
				WHERE		forum_id = ?
			)
			ORDER BY	isDraft ASC, msg_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0, 0));
		while ($row = $statement->fetchArray()) {
			$conversationID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import(($row['isDraft'] ? 'draft-' : '').$row['msg_id'], array(
				'subject' => StringUtil::decodeHTML($row['message_subject']),
				'time' => $row['message_time'],
				'userID' => $row['author_id'],
				'username' => $row['username'] ?: null,
				'isDraft' => $row['isDraft']
			));
			
			// add author
			$insertStatement->execute(array(
				$conversationID,
				ImportHandler::getInstance()->getNewID('com.woltlab.wcf.user', $row['author_id']),
				0,
				0,
				TIME_NOW
			));
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."privmsgs";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "(
				SELECT		msg_table.root_level,
						msg_table.msg_id,
						msg_table.author_id,
						user_table.username,
						msg_table.message_text,
						msg_table.bbcode_uid,
						msg_table.message_time,
						msg_table.enable_smilies,
						msg_table.enable_bbcode,
						msg_table.enable_sig,
						(SELECT COUNT(*) FROM ".$this->databasePrefix."attachments attachment_table WHERE attachment_table.post_msg_id = msg_table.msg_id AND in_message = ?) AS attachments
				FROM		".$this->databasePrefix."privmsgs msg_table
				LEFT JOIN	".$this->databasePrefix."users user_table
				ON		msg_table.author_id = user_table.user_id
			)
			UNION
			(
				SELECT		0 AS root_level,
						('draft-' || draft_table.draft_id) AS msg_id,
						draft_table.user_id AS author_id,
						user_table.username,
						draft_table.draft_message AS message_text,
						'' AS bbcode_uid,
						draft_table.save_time AS message_time,
						1 AS enable_smilies,
						1 AS enable_bbcode,
						1 AS enable_sig,
						0 AS attachments
				FROM		".$this->databasePrefix."drafts draft_table
				LEFT JOIN	".$this->databasePrefix."users user_table
				ON		draft_table.user_id = user_table.user_id
				WHERE		forum_id = ?
			)
			ORDER BY	msg_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(1, 0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['msg_id'], array(
				'conversationID' => ($row['root_level'] ?: $row['msg_id']),
				'userID' => $row['author_id'],
				'username' => $row['username'] ?: '',
				'message' => self::fixBBCodes(StringUtil::decodeHTML($row['message_text']), $row['bbcode_uid']),
				'time' => $row['message_time'],
				'attachments' => $row['attachments'],
				'enableSmilies' =>  $row['enable_smilies'],
				'enableHtml' => 0,
				'enableBBCodes' => $row['enable_bbcode'],
				'showSignature' => $row['enable_sig']
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."privmsgs_to";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		to_table.*, msg_table.root_level, msg_table.bcc_address, user_table.username
			FROM		".$this->databasePrefix."privmsgs_to to_table
			LEFT JOIN	".$this->databasePrefix."privmsgs msg_table
			ON		(msg_table.msg_id = to_table.msg_id)
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		to_table.user_id = user_table.user_id
			ORDER BY	to_table.msg_id DESC, to_table.user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$bcc = explode(':', $row['bcc_address']);
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => ($row['root_level'] ?: $row['msg_id']),
				'participantID' => $row['user_id'],
				'username' => $row['username'] ?: null,
				'hideConversation' => $row['pm_deleted'],
				'isInvisible' => in_array('u_'.$row['user_id'], $bcc) ? 1 : 0,
				'lastVisitTime' => $row['pm_unread'] ? 0 : 1
			), array('labelIDs' => ($row['folder_id'] > 0 ? array($row['folder_id']) : array())));
		}
	}
	
	/**
	 * Counts conversation attachments.
	 */
	public function countConversationAttachments() {
		return $this->countAttachments(1);
	}
	
	/**
	 * Exports conversation attachments.
	 */
	public function exportConversationAttachments($offset, $limit) {
		return $this->exportAttachments(1, $offset, $limit);
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
			ORDER BY	parent_id ASC, left_id ASC, forum_id ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$this->boardCache[$row['parent_id']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['forum_id'], array(
				'parentID' => ($board['parent_id'] ?: null),
				'position' => $board['left_id'],
				'boardType' => ($board['forum_type'] == self::BOARD_TYPE_LINK ? Board::TYPE_LINK : ($board['forum_type'] == self::BOARD_TYPE_CATEGORY ? Board::TYPE_CATEGORY : Board::TYPE_BOARD)),
				'title' => $board['forum_name'],
				'description' => $board['forum_desc'],
				'descriptionUseHtml' => 1, // cannot be disabled
				'externalURL' => $board['forum_link'],
				'countUserPosts' => 1, // cannot be disabled
				'isClosed' => $board['forum_status'] ? 1 : 0,
				'searchable' => $board['enable_indexing'] ? 1 : 0,
				'showSubBoards' => $board['display_subforum_list'] ? 1 : 0,
				'threadsPerPage' => $board['forum_topics_per_page'] ?: 0,
				'clicks' => $board['forum_posts'],
				'posts' => $board['forum_posts'],
				'threads' => $board['forum_topics']
			));
			
			$this->exportBoardsRecursively($board['forum_id']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."topics";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$boardIDs = array_keys(BoardCache::getInstance()->getBoards());
		
		$sql = "SELECT		topic_table.*
			FROM		".$this->databasePrefix."topics topic_table
			ORDER BY	topic_id ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['forum_id'] ?: $boardIDs[0], // map global annoucements to a random board
				'topic' => StringUtil::decodeHTML($row['topic_title']),
				'time' => $row['topic_time'],
				'userID' => $row['topic_poster'],
				'username' => $row['topic_first_poster_name'],
				'views' => $row['topic_views'],
				'isAnnouncement' => ($row['topic_type'] == self::TOPIC_TYPE_ANNOUCEMENT || $row['topic_type'] == self::TOPIC_TYPE_GLOBAL) ? 1 : 0,
				'isSticky' => $row['topic_type'] == self::TOPIC_TYPE_STICKY ? 1 : 0,
				'isDisabled' => 0,
				'isClosed' => $row['topic_status'] == self::TOPIC_STATUS_CLOSED ? 1 : 0,
				'movedThreadID' => ($row['topic_status'] == self::TOPIC_STATUS_LINK && $row['topic_moved_id']) ? $row['topic_moved_id'] : null,
				'movedTime' => TIME_NOW, // TODO
			);
			$additionalData = array();
			if ($row['topic_type'] == self::TOPIC_TYPE_GLOBAL) $additionalData['assignedBoards'] = $boardIDs;
			if ($row['topic_type'] == self::TOPIC_TYPE_ANNOUCEMENT) $additionalData['assignedBoards'] = array($row['forum_id']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['topic_id'], $data, $additionalData);
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
		$sql = "SELECT		post_table.*, user_table.username, editor.username AS editorName,
					(SELECT COUNT(*) FROM ".$this->databasePrefix."attachments attachment_table WHERE attachment_table.post_msg_id = post_table.post_id AND in_message = ?) AS attachments
			FROM		".$this->databasePrefix."posts post_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		post_table.poster_id = user_table.user_id
			LEFT JOIN	".$this->databasePrefix."users editor
			ON		post_table.post_edit_user = editor.user_id
			ORDER BY	post_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['post_id'], array(
				'threadID' => $row['topic_id'],
				'userID' => $row['poster_id'],
				'username' => ($row['post_username'] ?: ($row['username'] ?: '')),
				'subject' => StringUtil::decodeHTML($row['post_subject']),
				'message' => self::fixBBCodes(StringUtil::decodeHTML($row['post_text']), $row['bbcode_uid']),
				'time' => $row['post_time'],
				'isDisabled' => $row['post_approved'] ? 0 : 1,
				'isClosed' => $row['post_edit_locked'] ? 1 : 0,
				'editorID' => ($row['post_edit_user'] ?: null),
				'editor' => $row['editorName'] ?: '',
				'lastEditTime' => $row['post_edit_time'],
				'editCount' => $row['post_edit_count'],
				'editReason' => (!empty($row['post_edit_reason']) ? $row['post_edit_reason'] : ''),
				'attachments' => $row['attachments'],
				'enableSmilies' => $row['enable_smilies'],
				'enableHtml' => 0,
				'enableBBCodes' => $row['enable_bbcode'],
				'showSignature' => $row['enable_sig'],
				'ipAddress' => UserUtil::convertIPv4To6($row['poster_ip'])
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->countAttachments(0);
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		return $this->exportAttachments(0, $offset, $limit);
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."topics_watch";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		// TODO: This is untested. I cannot find the button to watch a topic…
		// TODO: Import bookmarks as watched threads as well?
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."topics_watch
			ORDER BY	topic_id ASC, user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
				'objectID' => $row['topic_id'],
				'userID' => $row['user_id'],
				'notification' => $row['notify_status']
			));
		}
	}
	
	/**
	 * Counts polls.
	 */
	public function countPolls() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."topics
			WHERE	poll_start <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		topic_id, topic_first_post_id, poll_title, poll_start, poll_length, poll_max_options, poll_vote_change,
					(SELECT COUNT(DISTINCT vote_user_id) FROM ".$this->databasePrefix."poll_votes votes WHERE votes.topic_id = topic.topic_id) AS poll_votes
			FROM		".$this->databasePrefix."topics topic
			WHERE		poll_start <> ?
			ORDER BY	topic_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll')->import($row['topic_id'], array(
				'objectID' => $row['topic_first_post_id'],
				'question' => $row['poll_title'],
				'time' => $row['poll_start'],
				'endTime' => $row['poll_length'] ? $row['poll_start'] + $row['poll_length'] : 0,
				'isChangeable' => ($row['poll_vote_change'] ? 1 : 0),
				'isPublic' => 0,
				'maxVotes' => $row['poll_max_options'],
				'votes' => $row['poll_votes']
			));
		}
	}
	
	/**
	 * Counts poll options.
	 */
	public function countPollOptions() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."poll_options";
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
			FROM		".$this->databasePrefix."poll_options
			ORDER BY	poll_option_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('post'));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option')->import($row['topic_id'].'-'.$row['poll_option_id'], array(
				'pollID' => $row['topic_id'],
				'optionValue' => $row['poll_option_text'],
				'showOrder' => $row['poll_option_id'],
				'votes' => $row['poll_option_total']
			));
		}
	}
	
	/**
	 * Counts poll option votes.
	 */
	public function countPollOptionVotes() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."poll_votes
			WHERE	vote_user_id <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports poll option votes.
	 */
	public function exportPollOptionVotes($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."poll_votes
			WHERE		vote_user_id <> ?
			ORDER BY	poll_option_id ASC, vote_user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.poll.option.vote')->import(0, array(
				'pollID' => $row['topic_id'],
				'optionID' => $row['topic_id'].'-'.$row['poll_option_id'],
				'userID' => $row['vote_user_id']
			));
		}
	}
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	(SELECT COUNT(*) FROM ".$this->databasePrefix."acl_users WHERE forum_id <> ?)
				+ (SELECT COUNT(*) FROM ".$this->databasePrefix."acl_groups WHERE forum_id <> ?) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0, 0));
		$row = $statement->fetchArray();
		return $row['count'] ? 2 : 0;
	}
	
	/**
	 * Exports ACLs.
	 */
	public function exportACLs($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."acl_options
			WHERE		is_local = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(1));
		$options = array();
		while ($row = $statement->fetchArray()) {
			$options[$row['auth_option_id']] = $row;
		}
		
		$condition = new PreparedStatementConditionBuilder();
		$condition->add('auth_option_id IN (?)', array(array_keys($options)));
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."acl_roles_data
			".$condition;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($condition->getParameters());
		$roles = array();
		while ($row = $statement->fetchArray()) {
			$roles[$row['role_id']][$row['auth_option_id']] = $row['auth_setting'];
		}
		
		$data = array();
		if ($offset == 0) {
			// groups
			$sql = "SELECT		*
				FROM		".$this->databasePrefix."acl_groups
				WHERE		forum_id <> ?
				ORDER BY	auth_role_id DESC";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array(0));
			$key = 'group';
		}
		else if ($offset == 1) {
			// users
			$sql = "SELECT		*
				FROM		".$this->databasePrefix."acl_users
				WHERE		forum_id <> ?
				ORDER BY	auth_role_id DESC";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array(0));
			$key = 'user';
		}
		
		while ($row = $statement->fetchArray()) {
			if ($row['auth_role_id'] != 0) {
				if (!isset($roles[$row['auth_role_id']])) continue;
					
				foreach ($roles[$row['auth_role_id']] as $optionID => $setting) {
					if (!isset($options[$optionID])) continue;
					
					$current = 1;
					if (isset($groups[$row[$key.'_id']][$row['forum_id']][$optionID])) {
						$current = $data[$row[$key.'_id']][$row['forum_id']][$optionID];
					}
					$data[$row[$key.'_id']][$row['forum_id']][$optionID] = min($current, $setting); // a setting of zero means never -> use minimum
				}
			}
			else {
				if (!isset($options[$row['auth_option_id']])) continue;
				
				$current = 1;
				if (isset($groups[$row[$key.'_id']][$row['forum_id']][$row['auth_option_id']])) {
					$current = $data[$row[$key.'_id']][$row['forum_id']][$row['auth_option_id']];
				}
				
				$data[$row[$key.'_id']][$row['forum_id']][$row['auth_option_id']] = min($current, $row['auth_setting']); // a setting of zero means never -> use minimum
			}
		}
		
		static $optionMapping = array(
			'f_announce' => array('canStartAnnouncement'),
			'f_attach' => array('canUploadAttachment'),
			'f_bbcode' => array(),
			'f_bump' => array(),
			'f_delete' => array('canDeleteOwnPost'),
			'f_download' => array('canDownloadAttachment', 'canViewAttachmentPreview'),
			'f_edit' => array('canEditOwnPost'),
			'f_email' => array(),
			'f_flash' => array(),
			'f_icons' => array(),
			'f_ignoreflood' => array(),
			'f_img' => array(),
			'f_list' => array('canViewBoard'),
			'f_noapprove' => array('canStartThreadWithoutModeration', 'canReplyThreadWithoutModeration'),
			'f_poll' => array('canStartPoll'),
			'f_post' => array('canStartThread'),
			'f_postcount' => array(),
			'f_print' => array(),
			'f_read' => array('canEnterBoard'),
			'f_reply' => array('canReplyThread'),
			'f_report' => array(),
			'f_search' => array(),
			'f_sigs' => array(),
			'f_smilies' => array(),
			'f_sticky' => array('canPinThread'),
			'f_subscribe' => array(),
			'f_user_lock' => array(),
			'f_vote' => array('canVotePoll'),
			'f_votechg' => array(),
			'm_approve' => array('canEnableThread'),
			'm_chgposter' => array(),
			'm_delete' => array(
				'canDeleteThread', 'canReadDeletedThread', 'canRestoreThread', 'canDeleteThreadCompletely',
				'canDeletePost', 'canReadDeletedPost', 'canRestorePost', 'canDeletePostCompletely'
			),
			'm_edit' => array('canEditPost'),
			'm_info' => array(),
			'm_lock' => array('canCloseThread', 'canReplyClosedThread'),
			'm_merge' => array('canMergeThread', 'canMergePost'),
			'm_move' => array('canMoveThread', 'canMovePost'),
			'm_report' => array(),
			'm_split' => array()
		);
		
		foreach ($data as $id => $forumData) {
			foreach ($forumData as $forumID => $settingData) {
				foreach ($settingData as $optionID => $value) {
					if (!isset($optionMapping[$options[$optionID]['auth_option']])) continue;
					foreach ($optionMapping[$options[$optionID]['auth_option']] as $optionName) {
						ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array(
							'objectID' => $forumID,
							$key.'ID' => $id,
							'optionValue' => $value
						), array(
							'optionName' => $optionName
						));
					}
				}
			}
		}
	}
	
	
	/**
	 * Counts smilies.
	 */
	public function countSmilies() {
		$sql = "SELECT	COUNT(DISTINCT smiley_url) AS count
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
		$sql = "SELECT		MIN(smiley_id) AS smiley_id,
					GROUP_CONCAT(code SEPARATOR '\n') AS aliases,
					smiley_url,
					MIN(smiley_order) AS smiley_order,
					GROUP_CONCAT(emotion SEPARATOR '\n') AS emotion
			FROM		".$this->databasePrefix."smilies
			GROUP BY	smiley_url
			ORDER BY	smiley_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array());
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'images/smilies/'.$row['smiley_url'];
			
			$aliases = explode("\n", $row['aliases']);
			$code = array_shift($aliases);
			$emotion = mb_substr($row['emotion'], 0, mb_strpos($row['emotion'], "\n") ?: mb_strlen($row['emotion'])); // we had to GROUP_CONCAT it because of SQL strict mode
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['smiley_id'], array(
				'smileyTitle' => $emotion,
				'smileyCode' => $code,
				'showOrder' => $row['smiley_order'],
				'aliases' => implode("\n", $aliases)
			), array('fileLocation' => $fileLocation));
		}
	}
	
	protected function countAttachments($conversation) {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."attachments
			WHERE	in_message = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($conversation ? 1 : 0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	protected function exportAttachments($conversation, $offset, $limit) {
		static $upload_path = null;
		if ($upload_path === null) {
			$sql = "SELECT	config_name, config_value
				FROM	".$this->databasePrefix."config
				WHERE	config_name IN (?)";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array('upload_path'));
			while ($row = $statement->fetchArray()) {
				$$row['config_name'] = $row['config_value'];
			}
		}
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."attachments
			WHERE		in_message = ?
			ORDER BY	attach_id DESC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array($conversation ? 1 : 0));
		while ($row = $statement->fetchArray()) {
			$fileLocation = FileUtil::addTrailingSlash($this->fileSystemPath.$upload_path).$row['physical_filename'];
			
			$isImage = 0;
			if ($row['mimetype'] == 'image/jpeg' || $row['mimetype'] == 'image/png' || $row['mimetype'] == 'image/gif') $isImage = 1;
			
			ImportHandler::getInstance()->getImporter('com.woltlab.'.($conversation ? 'wcf.conversation' : 'wbb').'.attachment')->import(0, array( // TODO: support inline attachments
				'objectID' => $row['post_msg_id'],
				'userID' => ($row['poster_id'] ?: null),
				'filename' => $row['real_filename'],
				'filesize' => $row['filesize'],
				'fileType' => $row['mimetype'],
				'isImage' => $isImage,
				'downloads' => $row['download_count'],
				'uploadTime' => $row['filetime']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	protected static function fixBBCodes($text, $uid) {
		// fix closing list tags
		$text = preg_replace('~\[/list:(u|o)~i', '[/list', $text);
		// fix closing list element tags
		$text = preg_replace('~\[/\*:m:'.$uid.'\]~i', '', $text);
		
		// remove uid
		$text = preg_replace('~\[(/?[^:\]]+):'.$uid.'~', '[$1', $text);
		$text = preg_replace('~:'.$uid.'\]~', ']', $text);
		
		// fix size bbcode
		$text = preg_replace_callback('~(?<=\[size=)\d+(?=\])~', function ($matches) {
			$wbbSize = 24;
			if ($matches[0] <= 50) $wbbSize = 8;
			else if ($matches[0] <= 85) $wbbSize = 10;
			else if ($matches[0] <= 150) $wbbSize = 14;
			else if ($matches[0] <= 200) $wbbSize = 18;
			
			return $wbbSize;
		}, $text);
		
		// convert smileys
		$text = preg_replace('~<!-- s(.+?) -->.+?<!-- s(?:.+?) -->~', '\\1', $text);
		
		// convert attachments
		$text = preg_replace('~\[attachment=(\d+)\]<!-- ia\\1 -->.*?<!-- ia\\1 -->\[/attachment\]~', '', $text); // TODO: not supported right now
		
		return $text;
	}
}
	