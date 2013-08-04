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
		'com.woltlab.wbb.acl' => 50
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
			/*'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',
				'com.woltlab.wbb.watchedThread',
				'com.woltlab.wbb.like',
				'com.woltlab.wcf.label'
			),*/
			'com.woltlab.wcf.conversation' => array(
				/*'com.woltlab.wcf.conversation.attachment',*/
				'com.woltlab.wcf.conversation.label'
			),
			/*'com.woltlab.wcf.smiley' => array()*/
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
					
				/*if (in_array('com.woltlab.wcf.conversation.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.attachment';*/
			}
		}
		/*
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
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';*/
		
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
			if ($row['group_id'] == 1) {
				// GUESTS
				ImportHandler::getInstance()->saveNewID('com.woltlab.wcf.user.group', 1, UserGroup::getGroupByType(UserGroup::GUESTS)->groupID);
				continue;
			}
			if ($row['group_id'] == 2) {
				// REGISTERED
				ImportHandler::getInstance()->saveNewID('com.woltlab.wcf.user.group', 2, UserGroup::getGroupByType(UserGroup::USERS)->groupID);
				continue;
			}
			if ($row['group_id'] == 6) {
				// BOTS
				continue;
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['group_id'], array(
				'groupName' => $row['group_name'],
				'groupType' => UserGroup::OTHER,
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
		$statement->execute(array(2)); // 2 = USER_IGNORE
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
	
		WCF::getDB()->beginTransaction();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['user_email'],
				'registrationDate' => $row['user_regdate'],
				'banned' => $row['banReason'] === null ? 0 : 1,
				'banReason' => $row['banReason'],
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['user_ip']),
				'signature' => self::fixBBCodes($row['user_sig'], $row['user_sig_bbcode_uid']),
				'signatureEnableBBCodes' => ($row['user_sig_bbcode_uid'] ? StringUtil::indexOf($row['user_sig'], $row['user_sig_bbcode_uid']) : 1),
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
		WCF::getDB()->commitTransaction();
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
				'requiredPoints' => $row['rank_min'],
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
				'followUserID' => $row['zebra_id'],
				'time' => 0
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
		$statement->execute(array(1, 3));
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
		$statement->execute(array(1, 3));
		while ($row = $statement->fetchArray()) {
			$extension = pathinfo($row['user_avatar'], PATHINFO_EXTENSION);
			switch ($row['user_avatar_type']) {
				case 1: // uploaded
					$location = FileUtil::addTrailingSlash($this->fileSystemPath.$avatar_path).$avatar_salt.'_'.intval($row['user_avatar']).'.'.$extension;
				break;
				case 3: // gallery
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
				'label' => StringUtil::substring($row['folder_name'], 0, 80)
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
		
		$sql = "SELECT		msg_table.*, user_table.username
			FROM		".$this->databasePrefix."privmsgs msg_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		msg_table.author_id = user_table.user_id
			WHERE		root_level = ?
			ORDER BY	msg_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			$conversationID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['msg_id'], array(
				'subject' => StringUtil::decodeHTML($row['message_subject']),
				'time' => $row['message_time'],
				'userID' => $row['author_id'],
				'username' => $row['username'],
				'isDraft' => 0 // TODO: fetch drafts from phpbb_drafts
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
		$sql = "SELECT		msg_table.*, user_table.username
			FROM		".$this->databasePrefix."privmsgs msg_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		msg_table.author_id = user_table.user_id
			ORDER BY	msg_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['msg_id'], array(
				'conversationID' => ($row['root_level'] ?: $row['msg_id']),
				'userID' => $row['author_id'],
				'username' => $row['username'],
				'message' => self::fixBBCodes(StringUtil::decodeHTML($row['message_text']), $row['bbcode_uid']),
				'time' => $row['message_time'],
				'attachments' => 0, // TODO
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
		$sql = "SELECT		to_table.*, msg_table.root_level, user_table.username
			FROM		".$this->databasePrefix."privmsgs_to to_table
			LEFT JOIN	".$this->databasePrefix."privmsgs msg_table
			ON		(msg_table.msg_id = to_table.msg_id)
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		to_table.user_id = user_table.user_id
			ORDER BY	to_table.msg_id DESC, to_table.user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => ($row['root_level'] ?: $row['msg_id']),
				'participantID' => $row['user_id'],
				'username' => $row['username'],
				'hideConversation' => $row['pm_deleted'],
				'isInvisible' => 0, // TODO
				'lastVisitTime' => $row['pm_unread'] ? 0 : 1
			), array('labelIDs' => ($row['folder_id'] > 0 ? array($row['folder_id']) : array())));
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
		
		return $text;
	}
}
	