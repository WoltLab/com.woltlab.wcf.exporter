<?php
namespace wcf\system\exporter;
use wcf\data\conversation\Conversation;

use wbb\data\board\Board;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\database\DatabaseException;
use wcf\system\importer\ImportHandler;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\FileUtil;
use wcf\util\MessageUtil;
use wcf\util\PasswordUtil;
use wcf\util\StringUtil;
use wcf\util\UserRegistrationUtil;
use wcf\util\UserUtil;

/**
 * Exporter for XenForo 1.2.x
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2014 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class XF12xExporter extends AbstractExporter {
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
		'com.woltlab.wcf.user.comment' => 'WallEntries',
		'com.woltlab.wcf.user.comment.response' => 'WallResponses',
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
	 * @see	\wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wcf.user.avatar' => 100,
		'com.woltlab.wcf.user.follower' => 100
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
			//	'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.comment',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				/*'com.woltlab.wbb.acl',
				'com.woltlab.wbb.attachment',
				'com.woltlab.wbb.poll',*/
				'com.woltlab.wbb.watchedThread'
			),
			'com.woltlab.wcf.conversation' => array(
				'com.woltlab.wcf.conversation.label'
			),
			// 'com.woltlab.wcf.smiley' => array()
		);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'library/XenForo/Application.php')) return false;
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
			
			//if (in_array('com.woltlab.wcf.user.option', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.option';
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
			
			if (in_array('com.woltlab.wcf.user.comment', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.user.comment';
				$queue[] = 'com.woltlab.wcf.user.comment.response';
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
			/*$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.acl', $this->selectedData)) $queue[] = 'com.woltlab.wbb.acl';
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';*/
			if (in_array('com.woltlab.wbb.watchedThread', $this->selectedData)) $queue[] = 'com.woltlab.wbb.watchedThread';
			/*if (in_array('com.woltlab.wbb.poll', $this->selectedData)) {
				$queue[] = 'com.woltlab.wbb.poll';
				$queue[] = 'com.woltlab.wbb.poll.option';
				$queue[] = 'com.woltlab.wbb.poll.option.vote';
			}*/
		}
		
		// smiley
		//if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';*/
		
		return $queue;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'xf_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."user_group";
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
			FROM		".$this->databasePrefix."user_group
			ORDER BY	user_group_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['user_group_id'], array(
				'groupName' => $row['title'],
				'groupType' => UserGroup::OTHER,
				'userOnlineMarking' => ($row['username_css'] ? '<span style="'.str_replace(array("\n", "\r"), '', $row['username_css']).'">%s</span>' : '%s'),
				'priority' => $row['display_style_priority']
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
		$sql = "SELECT		user_table.*, user_profile_table.*, INET_NTOA(ip_table.ip) AS ip, authenticate_table.scheme_class, authenticate_table.data AS passwordData
			FROM		".$this->databasePrefix."user user_table
			LEFT JOIN	".$this->databasePrefix."user_profile user_profile_table
			ON		user_table.user_id = user_profile_table.user_id
			LEFT JOIN	".$this->databasePrefix."user_authenticate authenticate_table
			ON		user_table.user_id = authenticate_table.user_id
			LEFT JOIN	".$this->databasePrefix."ip ip_table
			ON		user_table.user_id = ip_table.user_id
				AND	content_type = ?
				AND	action = ?
			ORDER BY	user_table.user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array('user', 'register'));
	
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['register_date'],
				'banned' => $row['is_banned'] ? 1 : 0,
				'banReason' => '',
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['ip']),
				'signature' => self::fixBBCodes($row['signature']),
				'signatureEnableBBCodes' => 1,
				'signatureEnableHtml' => 0,
				'signatureEnableSmilies' => 1,
				'lastActivityTime' => $row['last_activity']
			);
			$options = array();
			
			$additionalData = array(
				'groupIDs' => explode(',', $row['secondary_group_ids'].','.$row['user_group_id']),
				'options' => $options
			);
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['user_id'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordData = unserialize($row['passwordData']);
				switch ($row['scheme_class']) {
					case 'XenForo_Authentication_Core12':
						$password = 'xf12:'.$passwordData['hash'].':';
					break;
					case 'XenForo_Authentication_Core':
						$password = 'xf1:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					case 'XenForo_Authentication_MyBb':
						$password = 'mybb:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					case 'XenForo_Authentication_IPBoard':
						$password = 'ipb3:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
					case 'XenForo_Authentication_vBulletin':
						$password = 'vb3:'.$passwordData['hash'].':'.$passwordData['salt'];
					break;
				}
				$passwordUpdateStatement->execute(array($password, $newUserID));
			}
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."trophy_user_title";
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
			FROM		".$this->databasePrefix."trophy_user_title
			ORDER BY	minimum_points ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.rank')->import($row['minimum_points'], array(
				'groupID' => 2, // 2 = registered users
				'requiredPoints' => $row['minimum_points'],
				'rankTitle' => $row['title'],
				'rankImage' => '',
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
			FROM	".$this->databasePrefix."user_follow";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."user_follow
			ORDER BY	user_id ASC, follow_user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
				'userID' => $row['user_id'],
				'followUserID' => $row['follow_user_id'],
				'time' => $row['follow_date']
			));
		}
	}
	
	/**
	 * Counts wall entries.
	 */
	public function countWallEntries() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profile_post";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports wall entries.
	 */
	public function exportWallEntries($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."profile_post
			ORDER BY	profile_post_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment')->import($row['profile_post_id'], array(
				'objectID' => $row['profile_user_id'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'message' => $row['message'],
				'time' => $row['post_date']
			));
		}
	}
	
	/**
	 * Counts wall responses.
	 */
	public function countWallResponses() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profile_post_comment";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports wall responses.
	 */
	public function exportWallResponses($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."profile_post_comment
			ORDER BY	profile_post_comment_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.comment.response')->import($row['profile_post_comment_id'], array(
				'commentID' => $row['profile_post_id'],
				'time' => $row['comment_date'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'message' => $row['message'],
			));
		}
	}
	
	/**
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."user
			WHERE	avatar_date <> ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		user_id
			FROM		".$this->databasePrefix."user
			WHERE		avatar_date <> ?
			ORDER BY	user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			// TODO: read config
			$location = $this->fileSystemPath.'data/avatars/l/'.floor($row['user_id'] / 1000).'/'.$row['user_id'].'.jpg';
			
			if (!$imageSize = getimagesize($location)) continue;
			
			switch ($imageSize[2]) {
				case IMAGETYPE_JPEG:
					$extension = 'jpg';
				break;
				case IMAGETYPE_PNG:
					$extension = 'png';
				break;
				case IMAGETYPE_GIF:
					$extension = 'gif';
			}
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import($row['user_id'], array(
				'avatarName' => '',
				'avatarExtension' => $extension,
				'userID' => $row['user_id']
			), array('fileLocation' => $location));
		}
	}
	
	/**
	 * Counts conversation folders.
	 */
	public function countConversationFolders() {
		$this->countUsers();
	}
	
	/**
	 * Exports conversation folders.
	 */
	public function exportConversationFolders($offset, $limit) {
		$sql = "SELECT		user_id
			FROM		".$this->databasePrefix."user
			ORDER BY	user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(''));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.label')->import($row['userid'], array(
				'userID' => $row['user_id'],
				'label' => 'Star'
			));
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."conversation_master";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."conversation_master
			ORDER BY	conversation_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['conversation_id'], array(
				'subject' => $row['title'],
				'time' => $row['start_date'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'isDraft' => 0,
				'isClosed' => $row['conversation_open'] ? 0 : 1,
				'participantCanInvite' => $row['open_invite'] ? 1 : 0
			));
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."conversation_message";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		$sql = "SELECT		message_table.*, INET_NTOA(ip_table.ip) AS ip
			FROM		".$this->databasePrefix."conversation_message message_table
			LEFT JOIN	".$this->databasePrefix."ip ip_table
			ON		message_table.ip_id = ip_table.ip_id
			ORDER BY	message_table.message_id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['message_id'], array(
				'conversationID' => $row['conversation_id'],
				'userID' => $row['user_id'],
				'username' => $row['username'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['message_date'],
				'ipAddress' => $row['ip']
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."conversation_recipient";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		recipient_table.*, user_table.username, cuser_table.is_starred
			FROM		".$this->databasePrefix."conversation_recipient recipient_table
			LEFT JOIN	".$this->databasePrefix."user user_table
			ON		user_table.user_id = recipient_table.user_id
			LEFT JOIN	".$this->databasePrefix."conversation_user cuser_table
			ON		cuser_table.owner_user_id = recipient_table.user_id
				AND	cuser_table.conversation_id = recipient_table.conversation_id
			ORDER BY	recipient_table.conversation_id ASC, recipient_table.user_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => $row['conversation_id'],
				'participantID' => $row['user_id'],
				'username' => $row['username'] ?: '',
				'hideConversation' => ($row['recipient_state'] == 'deleted_ignored' ? Conversation::STATE_LEFT : ($row['recipient_state'] == 'deleted' ? Conversation::STATE_HIDDEN : Conversation::STATE_DEFAULT)),
				'isInvisible' => 0,
				'lastVisitTime' => $row['last_read_date']
			), array('labelIDs' => ($row['is_starred'] ? array($row['user_id']) : array())));
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."node
			WHERE	node_type_id IN (?, ?, ?)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('Forum', 'Category', 'LinkForum'));
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$sql = "SELECT		node.node_id AS nodeID, node.*, forum.*, link_forum.*
			FROM		".$this->databasePrefix."node node
			LEFT JOIN	".$this->databasePrefix."forum forum
			ON		node.node_id = forum.node_id
			LEFT JOIN	".$this->databasePrefix."link_forum link_forum
			ON		node.node_id = link_forum.node_id
			WHERE		node_type_id IN (?, ?, ?)
			ORDER BY	node.lft ASC";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('Forum', 'Category', 'LinkForum'));
		
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($row['nodeID'], array(
				'parentID' => ($row['parent_node_id'] ?: null),
				'position' => $row['lft'],
				'boardType' => ($row['node_type_id'] == 'Category' ? Board::TYPE_CATEGORY : ($row['node_type_id'] == 'Forum' ? Board::TYPE_BOARD : Board::TYPE_LINK)),
				'title' => $row['title'],
				'description' => $row['description'],
				'descriptionUseHtml' => 1, // cannot be disabled
				'externalURL' => $row['link_url'] ?: '',
				'countUserPosts' => $row['count_messages'] !== null ? $row['count_messages'] : 1,
				'isClosed' => $row['allow_posting'] ? 0 : 1,
				'isInvisible' => $row['display_in_list'] ? 0 : 1,
				'clicks' => $row['redirect_count'] ?: 0,
				'posts' => $row['message_count'] ?: 0,
				'threads' => $row['discussion_count'] ?: 0
			));
		}
	}
	
	/**
	 * Counts watched threads.
	 */
	public function countWatchedThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."thread_watch";
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
			FROM		".$this->databasePrefix."thread_watch
			ORDER BY	user_id ASC, thread_id ASC";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.watchedThread')->import(0, array(
				'objectID' => $row['thread_id'],
				'userID' => $row['user_id']
			));
		}
	}
	
	private static function fixBBCodes($message) {
		
		return $message;
	}
}
