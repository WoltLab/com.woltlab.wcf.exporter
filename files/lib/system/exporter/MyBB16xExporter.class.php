<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wbb\data\board\BoardCache;
use wcf\data\like\Like;
use wcf\data\object\type\ObjectTypeCache;
use wcf\data\user\group\UserGroup;
use wcf\data\user\option\UserOption;
use wcf\data\user\UserProfile;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\database\DatabaseException;
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
 * Exporter for MyBB 1.6.x
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2015 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class MyBB16xExporter extends AbstractExporter {
	protected static $knownProfileFields = array('Bio', 'Sex', 'Location');
	
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
				'com.woltlab.wcf.user.option',
				'com.woltlab.wcf.user.follower',
				'com.woltlab.wcf.user.rank'
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.acl',
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
		
		$sql = "SELECT	cache
			FROM	".$this->databasePrefix."datacache
			WHERE	title = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('version'));
		$row = $statement->fetchArray();
		$data = unserialize($row['cache']);
		
		if ($data['version_code'] < 1600) throw new DatabaseException('Cannot import MyBB 1.4.x or less', $this->database);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.wcf.user.avatar', $this->selectedData) || in_array('com.woltlab.wbb.attachment', $this->selectedData) || in_array('com.woltlab.wcf.smiley', $this->selectedData)) {
			if (empty($this->fileSystemPath) || !@file_exists($this->fileSystemPath . 'inc/mybb_group.php')) return false;
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
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				if (in_array('com.woltlab.wcf.conversation.label', $this->selectedData)) $queue[] = 'com.woltlab.wcf.conversation.label';
				
				$queue[] = 'com.woltlab.wcf.conversation';
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
		if (in_array('com.woltlab.wcf.smiley', $this->selectedData)) $queue[] = 'com.woltlab.wcf.smiley';
		
		return $queue;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getDefaultDatabasePrefix()
	 */
	public function getDefaultDatabasePrefix() {
		return 'mybb_';
	}
	
	/**
	 * Counts user groups.
	 */
	public function countUserGroups() {
		return $this->__getMaxID($this->databasePrefix."usergroups", 'gid');
	}
	
	/**
	 * Exports user groups.
	 */
	public function exportUserGroups($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."usergroups
			WHERE		gid BETWEEN ? AND ?
			ORDER BY	gid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			switch ($row['gid']) {
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
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.group')->import($row['gid'], array(
				'groupName' => $row['title'],
				'groupType' => $groupType,
				'userOnlineMarking' => str_replace('{username}', '%s', $row['namestyle']),
				'showOnTeamPage' => $row['showforumteam'],
				'priority' => $row['disporder'] ? pow(2, 10 - $row['disporder']) : 0
			));
		}
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID($this->databasePrefix."users", 'uid');
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// cache profile fields
		$profileFields = $knownProfileFields = array();
		$sql = "SELECT	*
			FROM	".$this->databasePrefix."profilefields";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if (in_array($row['name'], self::$knownProfileFields)) {
				$knownProfileFields[$row['name']] = $row;
			}
			else {
				$profileFields[] = $row;
			}
		}
		
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		userfields_table.*, user_table.*, activation_table.code AS activationCode, activation_table.type AS activationType,
					activation_table.misc AS newEmail, ban_table.reason AS banReason
			FROM		".$this->databasePrefix."users user_table
			LEFT JOIN	".$this->databasePrefix."awaitingactivation activation_table
			ON		user_table.uid = activation_table.uid
			LEFT JOIN	".$this->databasePrefix."userfields userfields_table
			ON		user_table.uid = userfields_table.ufid
			LEFT JOIN	".$this->databasePrefix."banned ban_table
			ON		user_table.uid = ban_table.uid AND ban_table.lifted <> ?
			WHERE		user_table.uid BETWEEN ? AND ?
			ORDER BY	user_table.uid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array(0, $offset + 1, $offset + $limit));
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
				'registrationIpAddress' => UserUtil::convertIPv4To6($row['regip']),
				'signature' => $row['signature'],
				'signatureEnableBBCodes' => 1,
				'signatureEnableHtml' => 0,
				'signatureEnableSmilies' => 1,
				'disableSignature' => $row['suspendsignature'],
				'disableSignatureReason' => '',
				'userTitle' => $row['usertitle'],
				'lastActivityTime' => $row['lastactive']
			);
			
			$birthday = \DateTime::createFromFormat('j-n-Y', $row['birthday']);
			// get user options
			$options = array(
				'location' => (isset($knownProfileFields['Location']) && !empty($row['fid'.$knownProfileFields['Location']['fid']])) ? $row['fid'.$knownProfileFields['Location']['fid']] : '',
				'birthday' => $birthday ? $birthday->format('Y-m-d') : '',
				'icq' => $row['icq'],
				'homepage' => $row['website']
			);
			
			// get gender
			if (isset($knownProfileFields['Sex']) && !empty($row['fid'.$knownProfileFields['Sex']['fid']])) {
				switch ($row['fid'.$knownProfileFields['Sex']['fid']]) {
					case 'Male':
						$options['gender'] = UserProfile::GENDER_MALE;
					break;
					case 'Female':
						$options['gender'] = UserProfile::GENDER_FEMALE;
				}
			}
			
			$additionalData = array(
				'groupIDs' => array_unique(ArrayUtil::toIntegerArray(explode(',', $row['additionalgroups'].','.$row['usergroup']))),
				'options' => $options
			);
			
			// handle user options
			foreach ($profileFields as $profileField) {
				if (!empty($row['fid'.$profileField['fid']])) {
					$additionalData['options'][$profileField['fid']] = $row['fid'.$profileField['fid']];
				}
			}
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['uid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(array('mybb1:'.$row['password'].':'.$row['salt'], $newUserID));
			}
		}
	}
	
	/**
	 * Counts user options.
	 */
	public function countUserOptions() {
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('name NOT IN (?)', array(self::$knownProfileFields));
		
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."profilefields
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports user options.
	 */
	public function exportUserOptions($offset, $limit) {
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('name NOT IN (?)', array(self::$knownProfileFields));
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."profilefields
			".$conditionBuilder."
			ORDER BY	fid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$selectOptions = '';
			switch ($row['type']) {
				case 'text':
				case 'textarea':
					// fine
				break;
				default:
					$type = explode("\n", $row['type'], 2);
					if (count($type) < 2) continue;
					switch ($type[0]) {
						case 'select':
							$row['type'] = $type[0];
						break;
						case 'multiselect':
							$row['type'] = 'multiSelect';
						break;
						case 'radio':
							$row['type'] = 'radioButton';
						break;
						default:
							continue;
					}
					
					$selectOptions = $type[1];
			}
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.option')->import($row['fid'], array(
				'categoryName' => 'profile.personal',
				'optionType' => $row['type'],
				'editable' => $row['editable'] ? UserOption::EDITABILITY_ALL : UserOption::EDITABILITY_ADMINISTRATOR,
				'required' => $row['required'],
				'selectOptions' => $selectOptions,
				'visible' => $row['hidden'] ? UserOption::VISIBILITY_ADMINISTRATOR | UserOption::VISIBILITY_OWNER : UserOption::VISIBILITY_ALL,
				'showOrder' => $row['disporder']
			), array('name' => $row['name']));
		}
	}
	
	/**
	 * Counts user ranks.
	 */
	public function countUserRanks() {
		$sql = "SELECT	(SELECT COUNT(*) FROM ".$this->databasePrefix."usertitles)
				+ (SELECT COUNT(*) FROM ".$this->databasePrefix."usergroups WHERE usertitle <> ? AND gid <> ?) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array('', 1));
		$row = $statement->fetchArray();
		
		return $row['count'];
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
				'requiredPoints' => $row['posts'] * 5,
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
					AND avatartype IN (?, ?)
			ORDER BY	uid";
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
			ORDER BY	uid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			if (empty($row['pmfolders'])) continue;
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
		$sql = "SELECT		COUNT(DISTINCT fromid, dateline) AS count
			FROM		".$this->databasePrefix."privatemessages";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$sql = "SELECT		message_table.*, user_table.username
			FROM		".$this->databasePrefix."privatemessages message_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		user_table.uid = message_table.fromid
			WHERE		pmid IN (
						SELECT		MIN(pmID)
						FROM		".$this->databasePrefix."privatemessages
						GROUP BY	fromid, dateline
					)
			ORDER BY	pmid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			$row['isDraft'] = $row['folder'] == 3 ? 1 : 0;
			
			$conversationID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($row['fromid'].'-'.$row['dateline'], array(
				'subject' => $row['subject'],
				'time' => $row['dateline'],
				'userID' => $row['fromid'],
				'username' => $row['username'] ?: '',
				'isDraft' => $row['isDraft']
			));
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($row['pmid'], array(
				'conversationID' => $row['fromid'].'-'.$row['dateline'],
				'userID' => $row['fromid'],
				'username' => $row['username'] ?: '',
				'message' => self::fixBBCodes($row['message']),
				'time' => $row['dateline'],
				'enableSmilies' => $row['smilieoff'] ? 0 : 1,
				'showSignature' => $row['includesig'] ? 1 : 0
			));
		}
	}
	
	/**
	 * Counts conversation recipients.
	 */
	public function countConversationUsers() {
		return $this->__getMaxID($this->databasePrefix."privatemessages", 'pmid');
	}
	
	/**
	 * Exports conversation recipients.
	 */
	public function exportConversationUsers($offset, $limit) {
		$sql = "SELECT		message_table.*, user_table.username
			FROM		".$this->databasePrefix."privatemessages message_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		user_table.uid = message_table.uid
			WHERE		pmid BETWEEN ? AND ?
			ORDER BY	pmid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$recipients = unserialize($row['recipients']);
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
				'conversationID' => $row['fromid'].'-'.$row['dateline'],
				'participantID' => $row['uid'],
				'username' => $row['username'] ?: '',
				'hideConversation' => $row['deletetime'] ? 1 : 0,
				'isInvisible' => (isset($recipients['bcc']) && in_array($row['uid'], $recipients['bcc'])) ? 1 : 0,
				'lastVisitTime' => $row['readtime']
			), array('labelIDs' => ($row['folder'] > 4 ? array($row['folder']) : array())));
		}
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
		$statement = $this->database->prepareStatement($sql);
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
		return $this->__getMaxID($this->databasePrefix."threads", 'tid');
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."threads
			WHERE		tid BETWEEN ? AND ?
			ORDER BY	tid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
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
			if ($row['prefix']) $additionalData['labels'] = array(ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $row['fid']).'-'.$row['prefix']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['tid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->__getMaxID($this->databasePrefix."posts", 'pid');
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$sql = "SELECT		post_table.*, user_table.username AS editor
			FROM		".$this->databasePrefix."posts post_table
			LEFT JOIN	".$this->databasePrefix."users user_table
			ON		user_table.uid = post_table.edituid
			WHERE		pid BETWEEN ? AND ?
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
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
				'ipAddress' => UserUtil::convertIPv4To6($row['ipaddress'])
			));
		}
	}
	
	/**
	 * Counts post attachments.
	 */
	public function countPostAttachments() {
		return $this->__getMaxID($this->databasePrefix."attachments", 'aid');
	}
	
	/**
	 * Exports post attachments.
	 */
	public function exportPostAttachments($offset, $limit) {
		static $uploadsPath = null;
		if ($uploadsPath === null) {
			$sql = "SELECT	value
				FROM	".$this->databasePrefix."settings
				WHERE	name = ?";
			$statement = $this->database->prepareStatement($sql);
			$statement->execute(array('uploadspath'));
			$row = $statement->fetchArray();
			$uploadsPath = $row['value'];
			if (!StringUtil::startsWith($uploadsPath, '/')) $uploadsPath = realpath($this->fileSystemPath.$uploadsPath);
		}
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."attachments
			WHERE		aid BETWEEN ? AND ?
			ORDER BY	aid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
		while ($row = $statement->fetchArray()) {
			$fileLocation = FileUtil::addTrailingSlash($uploadsPath).$row['attachname'];
			if (!file_exists($fileLocation)) continue;
			
			if ($imageSize = @getimagesize($fileLocation)) {
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
		return $this->__getMaxID($this->databasePrefix."threadsubscriptions", 'sid');
	}
	
	/**
	 * Exports watched threads.
	 */
	public function exportWatchedThreads($offset, $limit) {
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."threadsubscriptions
			WHERE		sid BETWEEN ? AND ?
			ORDER BY	sid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
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
		return $this->__getMaxID($this->databasePrefix."polls", 'pid');
	}
	
	/**
	 * Exports polls.
	 */
	public function exportPolls($offset, $limit) {
		$sql = "SELECT		poll_table.*, thread_table.firstpost
			FROM		".$this->databasePrefix."polls poll_table
			LEFT JOIN	".$this->databasePrefix."threads thread_table
			ON		poll_table.tid = thread_table.tid
			WHERE		pid BETWEEN ? AND ?
			ORDER BY	pid";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(array($offset + 1, $offset + $limit));
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
					AND adduid <> ?
					AND reputation <> ?
			ORDER BY	rid";
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
			foreach ($forums as $key => $forum) {
				if ($forum == -1) continue;
				
				$forums[$key] = ImportHandler::getInstance()->getNewID('com.woltlab.wbb.board', $forum);
			}
			
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
	
	/**
	 * Counts ACLs.
	 */
	public function countACLs() {
		$sql = "SELECT	(SELECT COUNT(*) FROM ".$this->databasePrefix."moderators)
				+ (SELECT COUNT(*) FROM ".$this->databasePrefix."forumpermissions) AS count";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports ACLs.
	 */
	public function exportACLs($offset, $limit) {
		// get ids
		$mod = $user = $group = array();
		$sql = "(
				SELECT	mid AS id, 'mod' AS type
				FROM	".$this->databasePrefix."moderators
			)
			UNION
			(
				SELECT	pid AS id, 'group' AS type
				FROM ".$this->databasePrefix."forumpermissions
			)
			ORDER BY	type, id";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		while ($row = $statement->fetchArray()) {
			${$row['type']}[] = $row['id'];
		}
		
		// mods
		if (!empty($mod)) {
			$modPermissionMap = array(
				'caneditposts' => array('canEditPost'),
				'candeleteposts' => array(
					'canDeleteThread',
					'canReadDeletedThread',
					'canRestoreThread',
					'canDeleteThreadCompletely',
					
					'canDeletePost',
					'canReadDeletedPost',
					'canRestorePost',
					'canDeletePostCompletely'
				),
				'canviewips' => array(),
				'canopenclosethreads' => array(
					'canCloseThread',
					'canReplyClosedThread',
					'canPinThread',
					
					'canClosePost'
				),
				'canmanagethreads' => array(
					'canEnableThread',
					'canMoveThread',
					'canMergeThread',
					
					'canEnablePost',
					'canMovePost',
					'canMergePost'
				)
			);
			
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('mid IN (?)', array($mod));
			
			$sql = "SELECT	*
				FROM	".$this->databasePrefix."moderators
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			while ($row = $statement->fetchArray()) {
				foreach ($modPermissionMap as $mybbPermission => $permissions) {
					foreach ($permissions as $permission) {
						ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array(
							'objectID' => $row['fid'],
							($row['isgroup'] ? 'groupID' : 'userID') => $row['id'],
							'optionValue' => $row[$mybbPermission]
						), array(
							'optionName' => $permission
						));
					}
				}
			}
		}
		
		// groups
		if (!empty($group)) {
			$groupPermissionMap = array(
				'canview' => array(
					'canViewBoard',
					'canEnterBoard'
				),
				'canviewthreads' => array('canReadThread'),
				'canonlyviewownthreads' => array(),
				'candlattachments' => array(
					'canDownloadAttachment',
					'canViewAttachmentPreview'
				),
				'canpostthreads' => array('canStartThread'),
				'canpostreplys' => array('canReplyThread'),
				'canpostattachments' => array('canUploadAttachment'),
				'canratethreads' => array(),
				'caneditposts' => array('canEditOwnPost'),
				'candeleteposts' => array('canDeleteOwnPost'),
				'candeletethreads' => array(),
				'caneditattachments' => array(),
				'canpostpolls' => array('canStartPoll'),
				'canvotepolls' => array('canVotePoll'),
				'cansearch' => array()
			);
			
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('pid IN (?)', array($group));
			
			$sql = "SELECT	*
				FROM	".$this->databasePrefix."forumpermissions
				".$conditionBuilder;
			$statement = $this->database->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			
			while ($row = $statement->fetchArray()) {
				foreach ($groupPermissionMap as $mybbPermission => $permissions) {
					foreach ($permissions as $permission) {
						ImportHandler::getInstance()->getImporter('com.woltlab.wbb.acl')->import(0, array(
							'objectID' => $row['fid'],
							'groupID' => $row['gid'],
							'optionValue' => $row[$mybbPermission]
						), array(
							'optionName' => $permission
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
			ORDER BY	sid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(array());
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath . $row['image'];
				
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.smiley')->import($row['sid'], array(
				'smileyTitle' => $row['name'],
				'smileyCode' => $row['find'],
				'showOrder' => $row['disporder']
			), array('fileLocation' => $fileLocation));
		}
	}
	
	private static function fixBBCodes($message) {
		static $videoRegex = null;
		static $quoteRegex = null;
		static $quoteCallback = null;
		static $attachmentRegex = null;
		
		if ($videoRegex === null) {
			$videoRegex = new Regex('\[video=[a-z]+\]');
			$quoteRegex = new Regex('\[quote=\'(.*?)\' pid=\'(\d+)\' dateline=\'\d+\'\]');
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
			
			$attachmentRegex = new Regex('\[attachment=([0-9]+)\]');
		}
		
		// attachment bbcodes
		$message = $attachmentRegex->replace($message, '[attach=\\1][/attach]');
		
		// code bbcodes
		$message = str_replace('[php]', '[code=php]', $message);
		
		// media bbcodes
		$message = $videoRegex->replace($message, '[media]\\1');
		$message = str_replace('[/video]', '[/media]', $message);
		
		// quotes
		$message = $quoteRegex->replace($message, $quoteCallback);
		
		// remove crap
		$message = MessageUtil::stripCrap($message);
		
		return $message;
	}
}
