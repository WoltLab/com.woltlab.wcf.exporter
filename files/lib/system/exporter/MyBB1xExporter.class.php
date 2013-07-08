<?php
namespace wcf\system\exporter;
use wcf\util\ArrayUtil;

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
use wcf\system\WCF;
use wcf\util\UserRegistrationUtil;
use wcf\util\StringUtil;

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
		'com.woltlab.wcf.user.follower' => 100
	);
	
	/**
	 * @see wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		return array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.group',
				'com.woltlab.wcf.user.rank',
				'com.woltlab.wcf.user.follower'
			)
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
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
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
		$sql = "SELECT		user_table.*, activation_table.code AS activationCode, activation_table.type AS activationType, activation_table.misc AS newEmail
			FROM		".$this->databasePrefix."users user_table
			LEFT JOIN	".$this->databasePrefix."awaitingactivation activation_table
			ON		user_table.uid = activation_table.uid
			ORDER BY	uid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
		
		WCF::getDB()->beginTransaction();
		while ($row = $statement->fetchArray()) {
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => $row['regdate'],
				'banned' => 0, // TODO: banned
				'banReason' => '',
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
		return $this->countUsers();
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$sql = "SELECT		uid, buddylist
			FROM		".$this->databasePrefix."users
			ORDER BY	uid";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute();
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
}
