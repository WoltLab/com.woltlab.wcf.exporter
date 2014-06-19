<?php
namespace wcf\system\exporter;
use wcf\data\user\group\UserGroup;
use wcf\system\importer\ImportHandler;
use wcf\system\request\LinkHandler;
use wcf\system\Callback;
use wcf\system\Regex;
use wcf\util\FileUtil;
use wcf\util\StringUtil;
use wcf\util\UserUtil;
use wcf\util\PasswordUtil;
use wcf\system\WCF;

/**
 * Exporter for Kunena 3.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2014 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class Kunena3xExporter extends AbstractExporter {
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
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
		'com.woltlab.wbb.attachment' => 'Attachments'
	);
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 200,
		'com.woltlab.wbb.thread' => 200,
		'com.woltlab.wbb.attachment' => 100
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.avatar',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.attachment'
			)
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
			$queue[] = 'com.woltlab.wcf.user';
			if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.avatar';
		}
	
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
				
			if (in_array('com.woltlab.wbb.attachment', $this->selectedData)) $queue[] = 'com.woltlab.wbb.attachment';
		}
	
		return $queue;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
	
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."kunena_users";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'libraries/kunena/model.php')) return false;
		}
	
		return true;
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."usergroups";
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
			FROM		".$this->databasePrefix."usergroups
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			switch ($row['id']) {
				case 1:
					$groupType = UserGroup::EVERYONE;
					break;
				case 2:
					$groupType = UserGroup::USERS;
					break;
				case 13:
					$groupType = UserGroup::GUESTS;
					break;
				default:
					$groupType = UserGroup::OTHER;
					break;
			}
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['id'], array(
				'groupName' => $row['title'],
				'groupType' => $groupType
			));
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."kunena_users kunena_users,
				".$this->databasePrefix."users users	
			WHERE	users.id = kunena_users.userid";
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
		$sql = "SELECT		kunena_users.*, users.*,
					(
						SELECT	GROUP_CONCAT(user_usergroup_map.group_id)
						FROM	".$this->databasePrefix."user_usergroup_map user_usergroup_map
						WHERE	user_usergroup_map.user_id = kunena_users.userid
					) AS groupIDs
			FROM		".$this->databasePrefix."kunena_users kunena_users,
					".$this->databasePrefix."users users
			WHERE		users.id = kunena_users.userid
			ORDER BY	kunena_users.userid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => StringUtil::getRandomID(),
				'email' => $row['email'],
				'banned' => ($row['banned'] ? 1 : 0),
				'registrationDate' => @strtotime($row['registerDate']),
				'lastActivityTime' => @strtotime($row['lastvisitDate']),
				'signature' => self::fixBBCodes($row['signature'])
			);

			// get user options
			$options = array(
				'location' => $row['location'],
				'birthday' => $row['birthdate'],
				'icq' => $row['icq'],
				'skype' => $row['skype'],
				'homepage' => $row['websiteurl'],
				'gender' => $row['gender']
			);
				
			$additionalData = array(
				'groupIDs' => explode(',', $row['groupIDs']),
				'options' => $options
			);
				
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['userid'], $data, $additionalData);
				
			// update password hash
			if ($newUserID) {
				$password = 'joomla3:'.$row['password'];
				if (substr($row['password'], 0, 3) == '$1$') {
					$password = 'cryptMD5:'.$row['password'];
				}
				else if (substr($row['password'], 0, 4) == '$2y$' || substr($row['password'], 0, 4) == '$2a$') {
					$password = PasswordUtil::getSaltedHash($row['password'], $row['password']);
				}
				else if (substr($row['password'], 0, 3) == '$P$') {
					$password = 'phpass:'.$row['password'];
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
			FROM	".$this->databasePrefix."kunena_ranks
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
			FROM		".$this->databasePrefix."kunena_ranks
			WHERE		rank_special = ?
			ORDER BY	rank_id";
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
	 * Counts user avatars.
	 */
	public function countUserAvatars() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."kunena_users
			WHERE	avatar <> ''";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user avatars.
	 */
	public function exportUserAvatars($offset, $limit) {
		$sql = "SELECT		userid, avatar
			FROM		".$this->databasePrefix."kunena_users
			WHERE		avatar <> ''
			ORDER BY	userid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$filepath = $this->fileSystemPath . 'media/kunena/avatars/' . $row['avatar'];
			if (file_exists($filepath)) {
				ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.avatar')->import(0, array(
					'avatarName' => basename($filepath),
					'avatarExtension' => pathinfo($filepath, PATHINFO_EXTENSION),
					'userID' => $row['userid']
				), array('fileLocation' => $filepath));
			}
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."kunena_categories";
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
			FROM		".$this->databasePrefix."kunena_categories
			ORDER BY	parent_id, ordering, id";
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
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['id'], array(
				'parentID' => ($board['parent_id'] ?: null),
				'position' => $board['ordering'],
				'boardType' => ($board['parent_id'] ? 0 : 1),
				'title' => $board['name'],
				'description' => $board['description'],
				'isClosed' => $board['locked'] ? 1 : 0
			));
				
			$this->exportBoardsRecursively($board['id']);
		}
	}
	

	/**
	 * Counts threads.
	 */
	public function countThreads() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."kunena_topics";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		kunena_topics.*
			FROM		".$this->databasePrefix."kunena_topics kunena_topics
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'boardID' => $row['category_id'],
				'topic' => $row['subject'],
				'time' => $row['first_post_time'],
				'userID' => $row['first_post_userid'],
				'username' => $row['first_post_guest_name'],
				'views' => $row['hits'],
				'isSticky' => $row['ordering'] == 1 ? 1 : 0,
				'isClosed' => $row['locked'] == 1 ? 1 : 0,
				'movedThreadID' => ($row['moved_id'] ? $row['moved_id'] : null)
			);
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['id'], $data);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."kunena_messages";
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
			FROM		".$this->databasePrefix."kunena_messages kunena_messages
			LEFT JOIN	".$this->databasePrefix."kunena_messages_text kunena_messages_text
			ON		(kunena_messages_text.mesid = kunena_messages.id)
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array(0));
		while ($row = $statement->fetchArray()) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['id'], array(
				'threadID' => $row['thread'],
				'userID' => $row['userid'],
				'username' => $row['name'],
				'subject' => $row['subject'],
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['time'],
				'ipAddress' => UserUtil::convertIPv4To6($row['ip']),
				'isClosed' => ($row['locked'] ? 1 : 0),
				'editorID' => null
			));
		}
	}
	
	/**
	 * Counts attachments.
	 */
	public function countAttachments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."kunena_attachments";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports attachments.
	 */
	public function exportAttachments($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."kunena_attachments
			ORDER BY	id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$fileLocation = FileUtil::addTrailingSlash($this->fileSystemPath . $row['folder']) . $row['filename'];
				
			$isImage = 0;
			if ($row['filetype'] == 'image/jpeg' || $row['filetype'] == 'image/png' || $row['filetype'] == 'image/gif') $isImage = 1;
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.attachment')->import($row['id'], array(
				'objectID' => $row['mesid'],
				'userID' => ($row['userid'] ?: null),
				'filename' => $row['filename'],
				'filesize' => $row['size'],
				'fileType' => $row['filetype'],
				'isImage' => $isImage
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private static function fixBBCodes($message) {
		static $quoteRegex = null;
		static $quoteCallback = null;
		
		if ($quoteRegex === null) {
			$quoteRegex = new Regex('\[quote="(.*?)" post=(\d+)\]', Regex::CASE_INSENSITIVE);
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
			'[/video]' => '[/media]',
			'[attachment' => '[attach',
			'[/attachment]' => '[/attach]'
		);
		$message = str_ireplace(array_keys($replacements), array_values($replacements), $message);
		
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
		
		// video
		$message = preg_replace('/\[video[^\]]*\]/i', '[media]', $message);
		
		// img
		$message = preg_replace('/\[img size=[^\]]*\]/i', '[img]', $message);
		
		// quotes
		$message = $quoteRegex->replace($message, $quoteCallback);
		
		return $message;
	}
}
