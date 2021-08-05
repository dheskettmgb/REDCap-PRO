<?php

namespace YaleREDCap\REDCapPRO;

use ExternalModules\AbstractExternalModule;

/**
 * Main EM Class
 */
class REDCapPRO extends AbstractExternalModule
{

    static $APPTITLE = "REDCapPRO";
    static $AUTH;
    static $SETTINGS;

    function __construct()
    {
        parent::__construct();
        $this::$AUTH = new Auth($this::$APPTITLE);
        $this::$SETTINGS = new ProjectSettings($this);
    }

    function redcap_every_page_top($project_id)
    {
        if (strpos($_SERVER["PHP_SELF"], "surveys") !== false) {
            return;
        }
        $role = SUPER_USER ? 3 : $this->getUserRole(USERID); // 3=admin/manager, 2=user, 1=monitor, 0=not found
        if ($role > 0) {
?>
            <script>
                setTimeout(function() {
                    let link = $("<div>" +
                        "<img src='<?= $this->getUrl('images/fingerprint_2.png'); ?>' style='width:16px; height:16px; position:relative; top:-2px'></img>" +
                        "&nbsp;" +
                        "<a href='<?= $this->getUrl('src/home.php'); ?>'><span id='RCPro-Link'><strong><font style='color:black;'>REDCap</font><em><font style='color:#900000;'>PRO</font></em></strong></span></a>" +
                        "</div>");
                    $('#app_panel').find('div.hang').last().after(link);
                }, 10);
            </script>
        <?php
        }
    }

    function redcap_survey_page_top(
        $project_id,
        $record,
        $instrument,
        $event_id,
        $group_id,
        $survey_hash,
        $response_id,
        $repeat_instance
    ) {

        // Initialize Authentication
        $this::$AUTH->init();

        // Participant is logged in to their account
        if ($this::$AUTH->is_logged_in()) {

            // Determine whether participant is enrolled in the study.
            $rcpro_participant_id = $this::$AUTH->get_participant_id();
            if (!$this->enrolledInProject($rcpro_participant_id, $project_id)) {
                $this->UiShowParticipantHeader("Not Enrolled");
                echo "<p style='text-align:center;'>You are not currently enrolled in this study.<br>";
                $study_contact = $this->getContactPerson("REDCapPRO - Not Enrolled");
                if (!isset($study_contact["name"])) {
                    echo "Please contact your study coordinator.";
                } else {
                    echo "Please contact your study coordinator:<br>" . $study_contact["info"];
                }
                echo "</p>";
                $this->exitAfterHook();
            }

            \REDCap::logEvent(
                "REDCapPRO Survey Accessed",                                        // action description
                "REDCapPRO User: " . $this::$AUTH->get_username() . "\n" .
                    "Instrument: ${instrument}\n",                                      // changes made
                NULL,                                                               // sql
                $record,                                                            // record
                $event_id,                                                          // event
                $project_id                                                         // project id
            );
            $this->log("REDCapPRO Survey Accessed", [
                "rcpro_username"  => $this::$AUTH->get_username(),
                "rcpro_user_id"   => $this::$AUTH->get_participant_id(),
                "record"          => $record,
                "event"           => $event_id,
                "instrument"      => $instrument,
                "survey_hash"     => $survey_hash,
                "response_id"     => $response_id,
                "repeat_instance" => $repeat_instance
            ]);
            echo "<style>
                .swal2-timer-progress-bar {
                    background: #900000 !important;
                }
                button.swal2-confirm:focus {
                    box-shadow: 0 0 0 3px rgb(144 0 0 / 50%) !important;
                }
                body.swal2-shown > [aria-hidden='true'] {
                    filter: blur(10px);
                }
                body > * {
                    transition: 0.1s filter linear;
                }
            </style>";
            echo "<script src='" . $this->getUrl("src/rcpro_base.js", true) . "'></script>";
            echo "<script>
                window.rcpro.logo = '" . $this->getUrl("images/RCPro_Favicon.svg") . "';
                window.rcpro.logoutPage = '" . $this->getUrl("src/logout.php", true) . "';
                window.rcpro.timeout_minutes = " . $this::$SETTINGS->getTimeoutMinutes() . ";
                window.rcpro.warning_minutes = " . $this::$SETTINGS->getTimeoutWarningMinutes() . ";
                window.rcpro.initTimeout();
            </script>";

            // Participant is not logged into their account
            // Store cookie to return to survey
        } else {
            $this::$AUTH->set_survey_url(APP_PATH_SURVEY_FULL . "?s=${survey_hash}");
            \Session::savecookie($this::$APPTITLE . "_survey_url", APP_PATH_SURVEY_FULL . "?s=${survey_hash}", 0, TRUE);
            $this::$AUTH->set_survey_active_state(TRUE);
            header("location: " . $this->getUrl("src/login.php", true) . "&s=${survey_hash}");
            $this->exitAfterHook();
        }
    }

    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1)
    {
        $role = SUPER_USER ? 3 : $this->getUserRole(USERID); // 3=admin/manager, 2=user, 1=monitor, 0=not found
        if ($role < 2) {
            return;
        }
        echo '<link href="' . $this->getUrl("lib/select2/select2.min.css") . '" rel="stylesheet" />
        <script src="' . $this->getUrl("lib/select2/select2.min.js") . '"></script>';

        $instrument = new Instrument($this, $instrument);
        $instrument->update_form();
    }

    /**
     * Hook that is triggered when a module is enabled in a Project
     * 
     * @param mixed $version
     * @param mixed $pid
     * 
     * @return void
     */
    function redcap_module_project_enable($version, $pid)
    {
        if (!$this->checkProject($pid)) {
            $this->addProject($pid);
        } else {
            $this->setProjectActive($pid, 1);
        }
    }

    /**
     * Hook that is triggered when a module is disabled in a Project
     * 
     * @param mixed $version
     * @param mixed $pid
     * 
     * @return void
     */
    function redcap_module_project_disable($version, $project_id)
    {
        $this->setProjectActive($project_id, 0);
    }



    /**
     * Increments the number of failed attempts at login for the provided id
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return BOOL|NULL
     */
    public function incrementFailedLogin(int $rcpro_participant_id)
    {
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value = value+1 WHERE log_id = ? AND name = 'failed_attempts'";
        try {
            $res = $this->query($SQL, [$rcpro_participant_id]);

            // Lockout username if necessary
            $this->lockoutLogin($rcpro_participant_id);
            return $res;
        } catch (\Exception $e) {
            $this->logError("Error incrementing failed login", $e);
            return NULL;
        }
    }

    /**
     * This both tests whether a user should be locked out based on the number
     * of failed login attempts and does the locking out.
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return BOOL|NULL
     */
    private function lockoutLogin(int $rcpro_participant_id)
    {
        try {
            $attempts = $this->checkUsernameAttempts($rcpro_participant_id);
            if ($attempts >= $this::$SETTINGS->getLoginAttempts()) {
                $lockout_ts = time() + $this::$SETTINGS->getLockoutDurationSeconds();
                $SQL = "UPDATE redcap_external_modules_log_parameters SET lockout_ts = ? WHERE log_id = ?;";
                $res = $this->query($SQL, [$lockout_ts, $rcpro_participant_id]);
                $status = $res ? "Successful" : "Failed";
                $this->log("Login Lockout ${status}", [
                    "rcpro_participant_id" => $rcpro_participant_id,
                    "rcpro_username"       => $this->getUserName($rcpro_participant_id)
                ]);
                return $res;
            } else {
                return TRUE;
            }
        } catch (\Exception $e) {
            $this->logError("Error doing login lockout", $e);
            return FALSE;
        }
    }

    /**
     * Resets the count of failed login attempts for the given id
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return BOOL|NULL
     */
    public function resetFailedLogin(int $rcpro_participant_id)
    {
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value=0 WHERE log_id=? AND name='failed_attempts';";
        try {
            return $this->query($SQL, [$rcpro_participant_id]);
        } catch (\Exception $e) {
            $this->logError("Error resetting failed login count", $e);
            return NULL;
        }
    }

    /**
     * Gets the IP address in an easy way
     * 
     * @return string - the ip address
     */
    public function getIPAddress()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Increments the number of failed login attempts for the given ip address
     * 
     * It also detects whether the ip should be locked out based on the number
     * of failed attempts and then does the locking.
     * 
     * @param string $ip Client IP address
     * 
     * @return int number of attempts INCLUDING current attempt
     */
    public function incrementFailedIp(string $ip)
    {
        if ($this->getSystemSetting('ip_lockouts') === null) {
            $this->setSystemSetting('ip_lockouts', json_encode(array()));
        }
        $ipLockouts = json_decode($this->getSystemSetting('ip_lockouts'), true);
        if (isset($ipLockouts[$ip])) {
            $ipStat = $ipLockouts[$ip];
        } else {
            $ipStat = array();
        }
        if (isset($ipStat["attempts"])) {
            $ipStat["attempts"]++;
            if ($ipStat["attempts"] >= $this::$SETTINGS->getLoginAttempts()) {
                $ipStat["lockout_ts"] = time() + $this::$SETTINGS->getLockoutDurationSeconds();
                $this->log("Locked out IP address", [
                    "rcpro_ip"   => $ip,
                    "lockout_ts" => $ipStat["lockout_ts"]
                ]);
            }
        } else {
            $ipStat["attempts"] = 1;
        }
        $ipLockouts[$ip] = $ipStat;
        $this->setSystemSetting('ip_lockouts', json_encode($ipLockouts));
        return $ipStat["attempts"];
    }

    /**
     * Resets the failed login attempt count for the given ip address
     * 
     * @param string $ip Client IP address
     * 
     * @return bool Whether or not the reset succeeded
     */
    public function resetFailedIp(string $ip)
    {
        try {
            if ($this->getSystemSetting('ip_lockouts') === null) {
                $this->setSystemSetting('ip_lockouts', json_encode(array()));
            }
            $ipLockouts = json_decode($this->getSystemSetting('ip_lockouts'), true);
            if (isset($ipLockouts[$ip])) {
                $ipStat = $ipLockouts[$ip];
            } else {
                $ipStat = array();
            }
            $ipStat["attempts"] = 0;
            $ipStat["lockout_ts"] = NULL;
            $ipLockouts[$ip] = $ipStat;
            $this->setSystemSetting('ip_lockouts', json_encode($ipLockouts));
            return TRUE;
        } catch (\Exception $e) {
            $this->logError("IP Login Attempt Reset Failed", $e);
            return FALSE;
        }
    }

    /**
     * Checks the number of failed login attempts for the given ip address
     * 
     * @param string $ip Client IP address
     * 
     * @return int number of failed login attempts for the given ip
     */
    private function checkIpAttempts(string $ip)
    {
        if ($this->getSystemSetting('ip_lockouts') === null) {
            $this->setSystemSetting('ip_lockouts', json_encode(array()));
        }
        $ipLockouts = json_decode($this->getSystemSetting('ip_lockouts'), true);
        if (isset($ipLockouts[$ip])) {
            $ipStat = $ipLockouts[$ip];
            if (isset($ipStat["attempts"])) {
                return $ipStat["attempts"];
            }
        }
        return 0;
    }

    /**
     * Determines whether given ip is currently locked out
     * 
     * @param string $ip Client IP address
     * 
     * @return bool whether ip is locked out
     */
    public function checkIpLockedOut(string $ip)
    {
        if ($this->getSystemSetting('ip_lockouts') === null) {
            $this->setSystemSetting('ip_lockouts', json_encode(array()));
        }
        $ipLockouts = json_decode($this->getSystemSetting('ip_lockouts'), true);
        $ipStat = $ipLockouts[$ip];

        if (isset($ipStat) && $ipStat["lockout_ts"] !== null && $ipStat["lockout_ts"] >= time()) {
            return $ipStat["lockout_ts"];
        }
        return FALSE;
    }

    /**
     * Gets number of failed login attempts for the given user by id
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return int number of failed login attempts
     */
    private function checkUsernameAttempts(int $rcpro_participant_id)
    {
        $SQL = "SELECT failed_attempts WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $res = $this->queryLogs($SQL, [$rcpro_participant_id]);
            return $res->fetch_assoc()["failed_attempts"];
        } catch (\Exception $e) {
            $this->logError("Failed to check username attempts", $e);
            return 0;
        }
    }

    /**
     * Checks whether given user (by id) is locked out
     * 
     * Returns the remaining lockout time in seconds for this participant
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return int number of seconds of lockout left
     */
    public function getUsernameLockoutDuration(int $rcpro_participant_id)
    {
        $SQL = "SELECT lockout_ts WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL);";
        try {
            $res = $this->queryLogs($SQL, [$rcpro_participant_id]);
            $lockout_ts = intval($res->fetch_assoc()["lockout_ts"]);
            $time_remaining = $lockout_ts - time();
            if ($time_remaining > 0) {
                return $time_remaining;
            }
        } catch (\Exception $e) {
            $this->logError("Failed to check username lockout", $e);
        }
    }

    /**
     * Returns number of consecutive failed login attempts
     * 
     * This checks both by username and by ip, and returns the larger
     * 
     * @param int|null $rcpro_participant_id
     * @param mixed $ip
     * 
     * @return int number of consecutive attempts
     */
    public function checkAttempts($rcpro_participant_id, $ip)
    {
        if ($rcpro_participant_id === null) {
            $usernameAttempts = 0;
        } else {
            $usernameAttempts = $this->checkUsernameAttempts($rcpro_participant_id);
        }
        $ipAttempts = $this->checkIpAttempts($ip);
        return max($usernameAttempts, $ipAttempts);
    }

    ////////////////////
    ///// SETTINGS /////
    ////////////////////

    /**
     * Gets the REDCapPRO role for the given REDCap user
     * @param string $username REDCap username
     * 
     * @return int role
     */
    public function getUserRole(string $username)
    {
        $managers = $this->getProjectSetting("managers");
        $users    = $this->getProjectSetting("users");
        $monitors = $this->getProjectSetting("monitors");

        $result = 0;

        if (in_array($username, $managers)) {
            $result = 3;
        } else if (in_array($username, $users)) {
            $result = 2;
        } else if (in_array($username, $monitors)) {
            $result = 1;
        }

        return $result;
    }

    /**
     * Updates the role of the given REDCap user 
     * 
     * @param string $username
     * @param string $oldRole
     * @param string $newRole
     * 
     * @return void
     */
    public function changeUserRole(string $username, string $oldRole, string $newRole)
    {
        $roles = array(
            "3" => $this->getProjectSetting("managers"),
            "2" => $this->getProjectSetting("users"),
            "1" => $this->getProjectSetting("monitors")
        );

        $oldRole = strval($oldRole);
        $newRole = strval($newRole);

        if (($key = array_search($username, $roles[$oldRole])) !== false) {
            unset($roles[$oldRole][$key]);
            $roles[$oldRole] = array_values($roles[$oldRole]);
        }
        if ($newRole !== "0") {
            $roles[$newRole][] = $username;
        }

        $this->setProjectSetting("managers", $roles["3"]);
        $this->setProjectSetting("users", $roles["2"]);
        $this->setProjectSetting("monitors", $roles["1"]);
    }

    /**
     * Gets project contact person's details
     * 
     * @return array of contact details
     */
    public function getContactPerson(string $subject = NULL)
    {
        $name  = $this->getProjectSetting("pc-name");
        $email = $this->getProjectSetting("pc-email");
        $phone = $this->getProjectSetting("pc-phone");

        $name_string = "<strong>Name:</strong> $name";
        $email_string = isset($email) ? $this->createEmailLink($email, $subject) : "";
        $phone_string = isset($phone) ? "<br><strong>Phone:</strong> $phone" : "";
        $info  = "${name_string} ${email_string} ${phone_string}";

        return [
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "info" => $info,
            "name_string" => $name_string,
            "email_string" => $email_string,
            "phone_string" => $phone_string
        ];
    }

    /**
     * @param string $email
     * @param string|null $subject
     * 
     * @return [type]
     */
    public function createEmailLink(string $email, ?string $subject)
    {
        if (!isset($subject)) {
            $subject = "REDCapPRO Inquiry";
        }
        $body = "";
        if ($this::$AUTH->is_logged_in()) {
            $username = $this::$AUTH->get_username();
            $body .= "REDCapPRO Username: ${username}\n";
        }
        if (PROJECT_ID) {
            $body .= "Project ID: " . PROJECT_ID;
            $body .= "\nProject Title: " . \REDCap::getProjectTitle();
        }
        $link = "mailto:${email}?subject=" . rawurlencode($subject) . "&body=" . rawurlencode($body);
        return "<br><strong>Email:</strong> <a href='${link}'>$email</a>";
    }



    /*
        Instead of creating a user table, we'll use the built-in log table (and log parameters table)

        So, there will be a message called "PARTICIPANT" 
        The log_id will be the id of the participant (rcpro_participant_id)
        The log's timestamp will act as the creation time
        and the parameters will be:
            * rcpro_username              - the coded username for this participant
            * email                 - email address
            * fname                 - first name
            * lname                 - last name
            * pw (hashed)           - hashed password
            * last_modified_ts      - timstamp of any updates to this log (php DateTime converted to unix timestamp)
            * failed_attempts       - number of failed login attempts for this username (not ip)
            * lockout_ts            - timestamp that a lockout will end (php DateTime converted to unix timestamp)
            * token                 - password set/reset token
            * token_ts              - timestamp the token is valid until (php DateTime converted to unix timestamp)
            * token_valid           - bool? 0/1? what is best here?
    */

    /*
        Insteam of a Project table:
        There will be a message called PROJECT
        The log_id will serve as the rcpro_project_id
        The timestamp will be the creation timestamp
        The parameters will be:
            * pid               - REDCap project id for this project
            * active            - whether the project is active or not. bool? 0/1?
    */

    /*
        Instead of a Link table:
        There will be a message called LINK
        The log_id will serve as the link id
        The timestamp will be the creation timestamp
        The parameters will be:
            * project           - rcpro_project_id (int)
            * participant       - rcpro_participant_id (int)
            * active            - bool? 0/1? This is whether the participant is enrolled 
                                  (i.e., if the link is active)
    */

    /**
     * Get hashed password for participant.
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return string|NULL hashed password or null
     */
    public function getHash(int $rcpro_participant_id)
    {
        try {
            $SQL = "SELECT pw WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL);";
            $res = $this->queryLogs($SQL, [$rcpro_participant_id]);
            return $res->fetch_assoc()['pw'];
        } catch (\Exception $e) {
            $this->logError("Error fetching password hash", $e);
        }
    }

    /**
     * Stores hashed password for the given participant id
     * 
     * @param string $hash - hashed password
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return bool|NULL success/failure/null
     */
    public function storeHash(string $hash, int $rcpro_participant_id)
    {
        try {
            $SQL = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'pw';";
            $res = $this->query($SQL, [$hash, $rcpro_participant_id]);
            $this->log("Password Hash Stored", [
                "rcpro_participant_id" => $rcpro_participant_id,
                "rcpro_username"       => $this->getUserName($rcpro_participant_id)
            ]);
            return $res;
        } catch (\Exception $e) {
            $this->logError("Error storing password hash", $e);
        }
    }

    /**
     * Adds participant entry into log table.
     * 
     * In so doing, it creates a unique username.
     * 
     * @param string $email Email Address
     * @param string $fname First Name
     * @param string $lname Last Name
     * 
     * @return string username of newly created participant
     */
    public function createParticipant(string $email, string $fname, string $lname)
    {
        $username     = $this->createUsername();
        $email_clean  = \REDCap::escapeHtml($email);
        $fname_clean  = \REDCap::escapeHtml($fname);
        $lname_clean  = \REDCap::escapeHtml($lname);
        $counter      = 0;
        $counterLimit = 90000000;
        while ($this->usernameIsTaken($username) && $counter < $counterLimit) {
            $username = $this->createUsername();
            $counter++;
        }
        if ($counter >= $counterLimit) {
            echo "Please contact your REDCap administrator.";
            return NULL;
        }
        try {
            $id = $this->log("PARTICIPANT", [
                "rcpro_username"   => $username,
                "email"            => $email_clean,
                "fname"            => $fname_clean,
                "lname"            => $lname_clean,
                "pw"               => "",
                "last_modified_ts" => time(),
                "lockout_ts"       => time(),
                "failed_attempts"  => 0,
                "token"            => "",
                "token_ts"         => time(),
                "token_valid"      => 0,
                "redcap_user"      => USERID
            ]);
            if (!$id) {
                throw new REDCapProException(["rcpro_username" => $username]);
            }
            $this->log("Participant Created", [
                "rcpro_user_id"  => $id,
                "rcpro_username" => $username,
                "redcap_user"    => USERID
            ]);
            return $username;
        } catch (\Exception $e) {
            $this->logError("Participant Creation Failed", $e);
        }
    }


    /**
     * Creates a "random" username
     * It creates an 8-digit username (between 10000000 and 99999999)
     * Of the form: XXX-XX-XXX
     * 
     * @return string username
     */
    private function createUsername()
    {
        return sprintf("%03d", random_int(100, 999)) . '-' .
            sprintf("%02d", random_int(0, 99)) . '-' .
            sprintf("%03d", random_int(0, 999));
    }

    /**
     * Checks whether username already exists in database.
     * 
     * @param string $username
     * 
     * @return boolean|NULL True if taken, False if free, NULL if error 
     */
    public function usernameIsTaken(string $username)
    {
        $SQL = "message = 'PARTICIPANT' AND rcpro_username = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->countLogs($SQL, [$username]);
            return $result > 0;
        } catch (\Exception $e) {
            $this->logError("Error checking if username is taken", $e);
        }
    }


    /**
     * Returns an array with participant information given a username
     * 
     * @param string $username
     * 
     * @return array|NULL user information
     */
    public function getParticipant(string $username)
    {
        if ($username === NULL) {
            return NULL;
        }
        $SQL = "SELECT log_id, rcpro_username, email, fname, lname WHERE message = 'PARTICIPANT' AND rcpro_username = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$username]);
            return $result->fetch_assoc();
        } catch (\Exception $e) {
            $this->logError("Error fetching participant information", $e);
        }
    }

    /**
     * Fetch username for given participant id
     * 
     * @param int $rcpro_participant_id - participant id
     * 
     * @return string|NULL username
     */
    public function getUserName(int $rcpro_participant_id)
    {
        $SQL = "SELECT rcpro_username WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$rcpro_participant_id]);
            return $result->fetch_assoc()["rcpro_username"];
        } catch (\Exception $e) {
            $this->logError("Error fetching username", $e);
        }
    }

    /**
     * Fetch participant id corresponding with given username
     * 
     * @param string $username
     * 
     * @return int|NULL RCPRO participant id
     */
    public function getParticipantIdFromUsername(string $username)
    {
        $SQL = "SELECT log_id WHERE message = 'PARTICIPANT' AND rcpro_username = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$username]);
            return $result->fetch_assoc()["log_id"];
        } catch (\Exception $e) {
            $this->logError("Error fetching id from username", $e);
        }
    }

    /**
     * Fetch participant id corresponding with given email
     * 
     * @param string $email
     * 
     * @return int|NULL RCPRO participant id
     */
    public function getParticipantIdFromEmail(string $email)
    {
        $SQL = "SELECT log_id WHERE message = 'PARTICIPANT' AND email = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$email]);
            return $result->fetch_assoc()["log_id"];
        } catch (\Exception $e) {
            $this->logError("Error fetching id from email", $e);
        }
    }

    /**
     * Fetch email corresponding with given participant id
     * 
     * @param int $rcpro_participant_id
     * 
     * @return string|NULL email address
     */
    public function getEmail(int $rcpro_participant_id)
    {
        $SQL = "SELECT email WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$rcpro_participant_id]);
            return $result->fetch_assoc()["email"];
        } catch (\Exception $e) {
            $this->logError("Error fetching email address", $e);
        }
    }

    /**
     * Fetches all the projects that the provided participant is enrolled in
     * 
     * This includes active and inactive projects
     * 
     * @param int $rcpro_participant_id
     * 
     * @return array array of arrays, each corresponding with a project
     */
    public function getParticipantProjects(int $rcpro_participant_id)
    {
        $SQL = "SELECT rcpro_project_id, active WHERE rcpro_participant_id = ? AND message = 'LINK' AND (project_id IS NULL OR project_id IS NOT NULL)";
        $projects = array();
        try {
            $result = $this->queryLogs($SQL, [$rcpro_participant_id]);
            if (!$result) {
                throw new REDCapProException(["rcpro_participant_id" => $rcpro_participant_id]);
            }
            while ($row = $result->fetch_assoc()) {
                array_push($projects, [
                    "rcpro_project_id" => $row["rcpro_project_id"],
                    "active"           => $row["active"],
                    "redcap_pid"       => $this->getPidFromProjectId($row["rcpro_project_id"])
                ]);
            }
            return $projects;
        } catch (\Exception $e) {
            $this->logError("Error fetching participant's projects", $e);
        }
    }

    /**
     * Use provided search string to find registered participants
     * 
     * @param string $search_term - text to search for
     * 
     * @return 
     */
    public function searchParticipants(string $search_term)
    {
        $SQL = "SELECT fname, lname, email, log_id, rcpro_username 
                WHERE message = 'PARTICIPANT' 
                AND (project_id IS NULL OR project_id IS NOT NULL) 
                AND (fname LIKE ? OR lname LIKE ? OR email LIKE ? OR rcpro_username LIKE ?)";
        try {
            return $this->queryLogs($SQL, [$search_term, $search_term, $search_term, $search_term]);
        } catch (\Exception $e) {
            $this->logError("Error performing livesearch", $e);
        }
    }

    /**
     * Grabs all registered participants
     * 
     * @return array|NULL of user arrays or null if error
     */
    public function getAllParticipants()
    {
        $SQL = "SELECT log_id, rcpro_username, email, fname, lname, lockout_ts WHERE message = 'PARTICIPANT' AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, []);
            $participants  = array();

            // grab participant details
            while ($row = $result->fetch_assoc()) {
                $participants[$row["log_id"]] = $row;
            }
            return $participants;
        } catch (\Exception $e) {
            $this->logError("Error fetching participants", $e);
        }
    }


    /**
     * get array of active enrolled participants given a rcpro project id
     * 
     * @param string $rcpro_project_id Project ID (not REDCap PID!)
     * 
     * @return array|NULL participants enrolled in given study
     */
    public function getProjectParticipants(string $rcpro_project_id)
    {
        $SQL = "SELECT rcpro_participant_id WHERE message = 'LINK' AND rcpro_project_id = ? AND active = 1 AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$rcpro_project_id]);
            $participants  = array();

            while ($row = $result->fetch_assoc()) {
                $participantSQL = "SELECT log_id, rcpro_username, email, fname, lname, lockout_ts WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
                $participantResult = $this->queryLogs($participantSQL, [$row["rcpro_participant_id"]]);
                $participant = $participantResult->fetch_assoc();
                $participants[$row["rcpro_participant_id"]] = $participant;
            }
            return $participants;
        } catch (\Exception $e) {
            $this->logError("Error fetching project participants", $e);
        }
    }


    /**
     * Determine whether email address already exists in database
     * 
     * @param string $email
     * 
     * @return boolean True if email already exists, False if not
     */
    public function checkEmailExists(string $email)
    {
        $SQL = "message = 'PARTICIPANT' AND email = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->countLogs($SQL, [$email]);
            return $result > 0;
        } catch (\Exception $e) {
            $this->logError("Error checking if email exists", $e);
        }
    }


    /**
     * Determines whether the current participant is enrolled in the project
     * 
     * @param int $rcpro_participant_id
     * @param int $pid PID of REDCap project
     * 
     * @return boolean TRUE if the participant is enrolled
     */
    public function enrolledInProject(int $rcpro_participant_id, int $pid)
    {
        $rcpro_project_id = $this->getProjectIdFromPID($pid);
        $SQL = "message = 'LINK' AND rcpro_project_id = ? AND rcpro_participant_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->countLogs($SQL, [$rcpro_project_id, $rcpro_participant_id]);
            return $result > 0;
        } catch (\Exception $e) {
            $this->logError("Error checking that participant is enrolled", $e);
        }
    }


    /**
     * Adds a project entry to table
     * 
     * @param int $pid - REDCap project PID
     * 
     * @return boolean success
     */
    public function addProject(int $pid)
    {
        try {
            return $this->log("PROJECT", [
                "pid"         => $pid,
                "active"      => 1,
                "redcap_user" => USERID
            ]);
        } catch (\Exception $e) {
            $this->logError("Error creating project entry", $e);
        }
    }

    /**
     * Set a project either active or inactive in Project Table
     * 
     * @param int $pid PID of project
     * @param int $active 0 to set inactive, 1 to set active
     * 
     * @return boolean success
     */
    public function setProjectActive(int $pid, int $active)
    {
        $rcpro_project_id = $this->getProjectIdFromPID($pid);
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'active'";
        try {
            $result = $this->query($SQL, [$active, $rcpro_project_id]);
            if ($result) {
                $this->log("Project Status Set", [
                    "rcpro_project_id" => $rcpro_project_id,
                    "active_status"    => $active,
                    "redcap_user"      => USERID
                ]);
            }
        } catch (\Exception $e) {
            $this->logError("Error setting project active status", $e);
        }
    }

    /**
     * Determine whether project exists in Project Table
     * 
     * Optionally additionally tests whether the project is currently active.
     * 
     * @param int $pid - REDCap Project PID
     * @param bool $check_active - Whether or not to additionally check whether active
     * 
     * @return bool
     */
    public function checkProject(int $pid, bool $check_active = FALSE)
    {
        $SQL = "SELECT active WHERE pid = ? and message = 'PROJECT' and (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$pid]);
            if ($result->num_rows == 0) {
                return FALSE;
            }
            $row = $result->fetch_assoc();
            return $check_active ? $row["active"] == "1" : TRUE;
        } catch (\Exception $e) {
            $this->logError("Error checking project", $e);
        }
    }


    /**
     * Get the project ID corresonding with a REDCap PID
     * 
     * returns null if REDCap project is not associated with REDCapPRO
     * @param int $pid REDCap PID
     * 
     * @return int rcpro project ID associated with the PID
     */
    public function getProjectIdFromPID(int $pid)
    {
        $SQL = "SELECT log_id WHERE message = 'PROJECT' AND pid = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$pid]);
            return $result->fetch_assoc()["log_id"];
        } catch (\Exception $e) {
            $this->logError("Error fetching project id from pid", $e);
        }
    }

    /**
     * Get the REDCap PID corresponding with a project ID
     * 
     * @param int $rcpro_project_id - rcpro project id
     * 
     * @return int REDCap PID associated with rcpro project id
     */
    public function getPidFromProjectId(int $rcpro_project_id)
    {
        $SQL = "SELECT pid WHERE message = 'PROJECT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$rcpro_project_id]);
            return $result->fetch_assoc()["pid"];
        } catch (\Exception $e) {
            $this->logError("Error fetching pid from project id", $e);
        }
    }

    /**
     * Fetch link id given participant and project id's
     * 
     * @param int $rcpro_participant_id
     * @param int $rcpro_project_id
     * 
     * @return int link id
     */
    public function getLinkId(int $rcpro_participant_id, int $rcpro_project_id)
    {
        $SQL = "SELECT log_id WHERE message = 'LINK' AND rcpro_participant_id = ? AND rcpro_project_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$rcpro_participant_id, $rcpro_project_id]);
            return $result->fetch_assoc()["log_id"];
        } catch (\Exception $e) {
            $this->logError("Error fetching link id", $e);
        }
    }

    /**
     * Enrolls a participant in a project
     * 
     * @param int $rcpro_participant_id
     * @param int $pid
     * 
     * @return int -1 if already enrolled, bool otherwise
     */
    public function enrollParticipant(int $rcpro_participant_id, int $pid)
    {
        // If project does not exist, create it.
        if (!$this->checkProject($pid)) {
            $this->addProject($pid);
        }
        $rcpro_project_id = $this->getProjectIdFromPID($pid);

        // Check that user is not already enrolled in this project
        if ($this->participantEnrolled($rcpro_participant_id, $rcpro_project_id)) {
            return -1;
        }

        // If there is already a link between this participant and project,
        // then activate it, otherwise create the link
        if ($this->linkAlreadyExists($rcpro_participant_id, $rcpro_project_id)) {
            $result = $this->setLinkActiveStatus($rcpro_participant_id, $rcpro_project_id, 1);
            if ($result) {
                $this->log("Enrolled Participant", [
                    "rcpro_participant_id" => $rcpro_participant_id,
                    "rcpro_username"       => $this->getUserName($rcpro_participant_id),
                    "rcpro_project_id"     => $rcpro_project_id,
                    "redcap_user"          => USERID
                ]);
            }
            return $result;
        } else {
            return $this->createLink($rcpro_participant_id, $rcpro_project_id);
        }
    }

    /**
     * Creates link between participant and project 
     * 
     * @param int $rcpro_participant_id
     * @param int $rcpro_project_id
     * 
     * @return bool success or failure
     */
    private function createLink(int $rcpro_participant_id, int $rcpro_project_id)
    {
        try {
            $this->log("LINK", [
                "rcpro_project_id"     => $rcpro_project_id,
                "rcpro_participant_id" => $rcpro_participant_id,
                "active"               => 1,
                "redcap_user"          => USERID
            ]);
            $this->log("Enrolled Participant", [
                "rcpro_participant_id" => $rcpro_participant_id,
                "rcpro_username"       => $this->getUserName($rcpro_participant_id),
                "rcpro_project_id"     => $rcpro_project_id,
                "redcap_user"          => USERID
            ]);
            return TRUE;
        } catch (\Exception $e) {
            $this->logError("Error enrolling participant", $e);
            return FALSE;
        }
    }

    /**
     * Set a link as active or inactive
     * 
     * @param int $rcpro_participant_id
     * @param int $rcpro_project_id
     * @param int $active                   - 0 for inactive, 1 for active
     * 
     * @return
     */
    private function setLinkActiveStatus(int $rcpro_participant_id, int $rcpro_project_id, int $active)
    {
        $link_id = $this->getLinkId($rcpro_participant_id, $rcpro_project_id);
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'active'";
        try {
            return $this->query($SQL, [$active, $link_id]);
        } catch (\Exception $e) {
            $this->logError("Error setting link activity", $e);
        }
    }

    /**
     * Checks whether participant is enrolled in given project
     * 
     * @param int $rcpro_participant_id
     * @param int $rcpro_project_id
     * 
     * @return bool
     */
    private function participantEnrolled(int $rcpro_participant_id, int $rcpro_project_id)
    {
        $SQL = "message = 'LINK' AND rcpro_participant_id = ? AND rcpro_project_id = ? AND active = 1 AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->countLogs($SQL, [$rcpro_participant_id, $rcpro_project_id]);
            return $result > 0;
        } catch (\Exception $e) {
            $this->logError("Error checking participant enrollment", $e);
        }
    }

    /**
     * Checks whether a link exists at all between participant and project - whether or not it is active
     * 
     * @param int $rcpro_participant_id
     * @param int $rcpro_project_id
     * 
     * @return bool
     */
    private function linkAlreadyExists(int $rcpro_participant_id, int $rcpro_project_id)
    {
        $SQL = "message = 'LINK' AND rcpro_participant_id = ? AND rcpro_project_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->countLogs($SQL, [$rcpro_participant_id, $rcpro_project_id]);
            return $result > 0;
        } catch (\Exception $e) {
            $this->logError("Error checking if link exists", $e);
        }
    }

    /**
     * Removes participant from project.
     * 
     * @param mixed $rcpro_participant_id
     * @param mixed $rcpro_project_id
     * 
     * @return [type]
     */
    public function disenrollParticipant($rcpro_participant_id, $rcpro_project_id)
    {
        try {
            $result = $this->setLinkActiveStatus($rcpro_participant_id, $rcpro_project_id, 0);
            if ($result) {
                $this->log("Disenrolled Participant", [
                    "rcpro_participant_id" => $rcpro_participant_id,
                    "rcpro_username"       => $this->getUserName($rcpro_participant_id),
                    "rcpro_project_id"     => $rcpro_project_id,
                    "redcap_user"          => USERID
                ]);
            }
            return $result;
        } catch (\Exception $e) {
            $this->logError("Error Disenrolling Participant", $e);
        }
    }



    /**
     * Create and store token for resetting participant's password
     * 
     * @param mixed $rcpro_participant_id
     * @param int $hours_valid - how long should the token be valid for
     * 
     * @return string token
     */
    public function createResetToken($rcpro_participant_id, int $hours_valid = 1)
    {
        $token = bin2hex(random_bytes(32));
        $token_ts = time() + ($hours_valid * 60 * 60);
        $SQL1 = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'token'";
        $SQL2 = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'token_ts'";
        $SQL3 = "UPDATE redcap_external_modules_log_parameters SET value = 1 WHERE log_id = ? AND name = 'token_valid'";
        try {
            $result1 = $this->query($SQL1, [$token, $rcpro_participant_id]);
            $result2 = $this->query($SQL2, [$token_ts, $rcpro_participant_id]);
            $result3 = $this->query($SQL3, [$rcpro_participant_id]);
            if (!$result1 || !$result2 || !$result3) {
                throw new REDCapProException(["rcpro_participant_id" => $rcpro_participant_id]);
            }
            return $token;
        } catch (\Exception $e) {
            $this->logError("Error creating reset token", $e);
        }
    }

    /**
     * Verify that the supplied password reset token is valid.
     * 
     * @param string $token
     * 
     * @return array with participant id and username
     */
    public function verifyPasswordResetToken(string $token)
    {
        $SQL = "SELECT log_id, rcpro_username WHERE message = 'PARTICIPANT' AND token = ? AND token_ts > ? AND token_valid = 1 AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $result = $this->queryLogs($SQL, [$token, time()]);
            if ($result->num_rows > 0) {
                $result_array = $result->fetch_assoc();
                $this->log("Password Token Verified", [
                    'rcpro_participant_id' => $result_array['log_id'],
                    'rcpro_username'       => $result_array['rcpro_username']
                ]);
                return $result_array;
            }
        } catch (\Exception $e) {
            $this->logError("Error verifying password reset token", $e);
        }
    }

    /**
     * Sets the password reset token as invalid/expired
     * 
     * @param mixed $rcpro_participant_id id of participant
     * 
     * @return bool|null success or failure
     */
    public function expirePasswordResetToken($rcpro_participant_id)
    {
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value = 0 WHERE log_id = ? AND name = 'token_valid'";
        try {
            return $this->query($SQL, [$rcpro_participant_id]);
        } catch (\Exception $e) {
            $this->logError("Error expiring password reset token.", $e);
        }
    }

    /**
     * Send an email with a link for the participant to reset their email
     * 
     * @param mixed $rcpro_participant_id
     * 
     * @return void
     */
    public function sendPasswordResetEmail($rcpro_participant_id)
    {
        try {
            // generate token
            $token    = $this->createResetToken($rcpro_participant_id);
            $to       = $this->getEmail($rcpro_participant_id);
            $username = $this->getUserName($rcpro_participant_id);
            $username_clean = \REDCap::escapeHtml($username);

            // create email
            $subject = "REDCapPRO - Password Reset";
            $from = "noreply@REDCapPRO.com";
            $body = "<html><body><div>
            <img src='" . $this->getUrl("images/RCPro_Logo.svg") . "' alt='img' width='500px'><br>
            <p>Hello,
            <br>We have received a request to reset your account password. If you did not make this request, you can ignore this email.<br>
            <br>To reset your password, click the link below.
            <br>This is your username: <strong>${username_clean}</strong><br>
            <br>Click <a href='" . $this->getUrl("src/reset-password.php", true) . "&t=${token}'>here</a> to reset your password.
            <br><em>That link is only valid for the next hour. If you need a new link, click <a href='" . $this->getUrl("src/forgot-password.php", true) . "'>here</a>.</em>
            </p>
            <br>";
            if (defined("PROJECT_ID")) {
                $study_contact = $this->getContactPerson("REDCapPRO - Reset Password");
                if (!isset($study_contact["name"])) {
                    $body .= "<p>If you have any questions, contact a member of the study team.</p>";
                } else {
                    $body .= "<p>If you have any questions, contact a member of the study team:<br>" . $study_contact["info"] . "</p>";
                }
            } else {
                $body .= "<p>If you have any questions, contact a member of the study team.</p>";
            }
            $body .= "</body></html></div>";

            $result = \REDCap::email($to, $from, $subject, $body);
            $status = $result ? "Sent" : "Failed to send";
            $this->log("Password Reset Email - ${status}", [
                "rcpro_participant_id" => $rcpro_participant_id,
                "rcpro_username"       => $username_clean,
                "rcpro_email"          => $to,
                "redcap_user"          => USERID
            ]);
            return $result;
        } catch (\Exception $e) {
            $this->log("Password Reset Failed", [
                "rcpro_participant_id" => $rcpro_participant_id,
                "redcap_user"          => USERID
            ]);
            $this->logError("Error sending password reset email", $e);
        }
    }

    /**
     * Send new participant email to set password
     * 
     * This acts as an email verification process as well
     * 
     * @param string $username
     * @param string $email
     * @param string $fname
     * @param string $lname
     * 
     * @return bool|NULL
     */
    public function sendNewParticipantEmail(string $username, string $email, string $fname, string $lname)
    {
        // generate token
        try {
            $rcpro_participant_id = $this->getParticipantIdFromUsername($username);
            $hours_valid          = 24;
            $token                = $this->createResetToken($rcpro_participant_id, $hours_valid);

            // create email
            $subject = "REDCapPRO - Account Created";
            $from    = "noreply@REDCapPRO.com";
            $body    = "<html><body><div>
            <img src='" . $this->getUrl("images/RCPro_Logo.svg") . "' alt='img' width='500px'><br>
            <p>Hello ${fname} ${lname},
            <br>An account has been created for you in order to take part in a research study.<br>
            This is your username: <strong>${username}</strong><br>
            Write it down someplace safe, because you will need to know your username to take part in the study.</p>

            <p>To use your account, first you will need to create a password. 
            <br>Click <a href='" . $this->getUrl("src/create-password.php", true) . "&t=${token}'>this link</a> to create your password.
            <br>That link will only work for the next $hours_valid hours.
            </p>
            <br>";
            if (defined("PROJECT_ID")) {
                $study_contact = $this->getContactPerson("REDCapPRO - Username Inquiry");
                if (!isset($study_contact["name"])) {
                    $body .= "<p>If you have any questions, contact a member of the study team.</p>";
                } else {
                    $body .= "<p>If you have any questions, contact a member of the study team:<br>" . $study_contact["info"] . "</p>";
                }
            } else {
                $body .= "<p>If you have any questions, contact a member of the study team.</p>";
            }
            $body .= "</body></html></div>";

            return \REDCap::email($email, $from, $subject, $body);
        } catch (\Exception $e) {
            $this->logError("Error sending new user email", $e);
        }
    }

    /**
     * Sends an email that just contains the participant's username.
     * 
     * @param string $email
     * @param string $username
     * 
     * @return bool|NULL success or failure
     */
    public function sendUsernameEmail(string $email, string $username)
    {
        $subject = "REDCapPRO - Username";
        $from    = "noreply@REDCapPRO.com";
        $body    = "<html><body><div>
        <img src='" . $this->getUrl("images/RCPro_Logo.svg") . "' alt='img' width='500px'><br>
        <p>Hello,</p>
        <p>This is your username: <strong>${username}</strong><br>
        Write it down someplace safe.</p>

        <p>If you did not request this email, please disregard.<br>";
        if (defined("PROJECT_ID")) {
            $study_contact = $this->getContactPerson("REDCapPRO - Username Inquiry");
            if (!isset($study_contact["name"])) {
                $body .= "If you have any questions, contact a member of the study team.</p>";
            } else {
                $body .= "If you have any questions, contact a member of the study team:<br>" . $study_contact["info"] . "</p>";
            }
        } else {
            $body .= "If you have any questions, contact a member of the study team.</p>";
        }
        $body .= "</body></html></div>";

        try {
            return \REDCap::email($email, $from, $subject, $body);
        } catch (\Exception $e) {
            $this->logError("Error sending username email", $e);
        }
    }

    /**
     * Updates the email address for the given participant
     * 
     * It then sends a confirmation email to the new address, cc'ing the old
     * 
     * @param int $rcpro_participant_id
     * @param string $new_email - email address that 
     * 
     * @return bool|NULL
     */
    public function changeEmailAddress(int $rcpro_participant_id, string $new_email)
    {
        $current_email = $this->getEmail($rcpro_participant_id);
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'email'";
        try {
            $result = $this->query($SQL, [$new_email, $rcpro_participant_id]);
            if ($result) {
                $this->log("Changed Email Address", [
                    "rcpro_participant_id" => $rcpro_participant_id,
                    "old_email"            => $current_email,
                    "new_email"            => $new_email,
                    "redcap_user"          => USERID
                ]);
                $username = $this->getUserName($rcpro_participant_id);
                return $this->sendEmailUpdateEmail($username, $new_email, $current_email);
            } else {
                throw new REDCapProException(["rcpro_participant_id" => $rcpro_participant_id]);
            }
        } catch (\Exception $e) {
            $this->logError("Error changing email address", $e);
        }
    }

    public function sendEmailUpdateEmail(string $username, string $new_email, string $old_email)
    {
        $subject = "REDCapPRO - Email Address Changed";
        $from    = "noreply@REDCapPRO.com";
        $old_email_clean = \REDCap::escapeHtml($old_email);
        $new_email_clean = \REDCap::escapeHtml($new_email);
        $body    = "<html><body><div>
        <img src='" . $this->getUrl("images/RCPro_Logo.svg") . "' alt='img' width='500px'><br>
        <p>Hello,</p>
        <p>Your email for username <strong>${username}</strong> was just changed.<br>
            <ul>
                <li><strong>Old email:</strong> ${old_email_clean}</li>
                <li><strong>New email:</strong> ${new_email_clean}</li>
            </ul>
        </p>";
        if (defined("PROJECT_ID")) {
            $study_contact = $this->getContactPerson("REDCapPRO - Reset Password");
            if (!isset($study_contact["name"])) {
                $body .= "<p><strong>If you did not request this change, please contact a member of the study team!</strong></p>";
            } else {
                $body .= "<p><strong>If you did not request this change, please contact a member of the study team!</strong><br>" . $study_contact["info"] . "</p>";
            }
        } else {
            $body .= "<p><strong>If you did not request this change, please contact a member of the study team!</strong></p>";
        }
        $body .= "</body></html></div>";

        try {
            return \REDCap::email($new_email, $from, $subject, $body, $old_email);
        } catch (\Exception $e) {
            $this->logError("Error sending email reset email", $e);
        }
    }

    /*-------------------------------------*\
    |             UI FORMATTING             |
    \*-------------------------------------*/

    public function UiShowParticipantHeader(string $title)
    {
        echo '<!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <title>REDCapPRO ' . $title . '</title>
                    <link rel="shortcut icon" href="' . $this->getUrl("images/favicon.ico") . '"/>
                    <link rel="icon" type="image/png" sizes="32x32" href="' . $this->getUrl("images/favicon-32x32.png") . '">
                    <link rel="icon" type="image/png" sizes="16x16" href="' . $this->getUrl("images/favicon-16x16.png") . '">
                    <link rel="stylesheet" href="' . $this->getUrl("lib/bootstrap/css/bootstrap.min.css") . '">
                    <script async src="' . $this->getUrl("lib/bootstrap/js/bootstrap.bundle.min.js") . '"></script>
                    <style>
                        body { font: 14px sans-serif; }
                        .wrapper { width: 360px; padding: 20px; }
                        .form-group { margin-top: 20px; }
                        .center { display: flex; justify-content: center; align-items: center; }
                        img#rcpro-logo { position: relative; left: -125px; }
                    </style>
                </head>
                <body>
                    <div class="center">
                        <div class="wrapper">
                            <img id="rcpro-logo" src="' . $this->getUrl("images/RCPro_Logo.svg") . '" width="500px"></img>
                            <hr>
                            <div style="text-align: center;"><h2>' . $title . '</h2></div>';
    }

    public function UiEndParticipantPage()
    {
        echo '</div></div></body></html>';
    }

    public function UiShowHeader(string $page)
    {
        $role = SUPER_USER ? 3 : $this->getUserRole(USERID); // 3=admin/manager, 2=user, 1=monitor, 0=not found
        $header = "
        <style>
            .rcpro-nav a {
                color: #900000 !important;
                font-weight: bold !important;
            }
            .rcpro-nav a.active:hover {
                color: #900000 !important;
                font-weight: bold !important;
                outline: none !important;
            }
            .rcpro-nav a:hover:not(.active), a:focus {
                color: #900000 !important;
                font-weight: bold !important;
                border: 1px solid #c0c0c0 !important;
                background-color: #e1e1e1 !important;
                outline: none !important;
            }
            .rcpro-nav a:not(.active) {
                background-color: #f7f6f6 !important;
                border: 1px solid #e1e1e1 !important;
                outline: none !important;
            }
        </style>
        <link rel='shortcut icon' href='" . $this->getUrl('images/favicon.ico') . "'/>
        <link rel='icon' type='image/png' sizes='32x32' href='" . $this->getUrl('images/favicon-32x32.png') . "'>
        <link rel='icon' type='image/png' sizes='16x16' href='" . $this->getUrl('images/favicon-16x16.png') . "'>
        <div>
            <img src='" . $this->getUrl("images/RCPro_Logo.svg") . "' width='500px'></img>
            <br>
            <nav style='margin-top:20px;'><ul class='nav nav-tabs rcpro-nav'>
                <li class='nav-item'>
                    <a class='nav-link " . ($page === "Home" ? "active" : "") . "' aria-current='page' href='" . $this->getUrl("src/home.php") . "'>
                    <i class='fas fa-home'></i>
                    Home</a>
                </li>";
        if ($role >= 1) {
            $header .= "<li class='nav-item'>
                            <a class='nav-link " . ($page === "Manage" ? "active" : "") . "' href='" . $this->getUrl("src/manage.php") . "'>
                            <i class='fas fa-users-cog'></i>
                            Manage Participants</a>
                        </li>";
        }
        if ($role >= 2) {
            $header .= "<li class='nav-item'>
                            <a class='nav-link " . ($page === "Enroll" ? "active" : "") . "' href='" . $this->getUrl("src/enroll.php") . "'>
                            <i class='fas fa-user-check'></i>
                            Enroll</a>
                        </li>
                        <li class='nav-item'>
                            <a class='nav-link " . ($page === "Register" ? "active" : "") . "' href='" . $this->getUrl("src/register.php") . "'>
                            <i class='fas fa-id-card'></i>
                            Register</a>
                        </li>";
        }
        if ($role > 2) {
            $header .= "<li class='nav-item'>
                            <a class='nav-link " . ($page === "Users" ? "active" : "") . "' href='" . $this->getUrl("src/manage-users.php") . "'>
                            <i class='fas fa-users'></i>
                            Study Staff</a>
                        </li>
                        <li class='nav-item'>
                            <a class='nav-link " . ($page === "Logs" ? "active" : "") . "' href='" . $this->getUrl("src/logs.php") . "'>
                            <i class='fas fa-list'></i>
                            Logs</a>
                        </li>";
        }
        $header .= "</ul></nav>
            </div>";
        echo $header;
    }

    public function UiShowControlCenterHeader(string $page)
    {
        $header = "
        <style>
            .rcpro-nav a {
                color: #900000 !important;
                font-weight: bold !important;
            }
            .rcpro-nav a.active:hover {
                color: #900000 !important;
                font-weight: bold !important;
                outline: none !important;
            }
            .rcpro-nav a:hover:not(.active), a:focus {
                color: #900000 !important;
                font-weight: bold !important;
                border: 1px solid #c0c0c0 !important;
                background-color: #e1e1e1 !important;
                outline: none !important;
            }
            .rcpro-nav a:not(.active) {
                background-color: #f7f6f6 !important;
                border: 1px solid #e1e1e1 !important;
                outline: none !important;
            }
        </style>
        <link rel='shortcut icon' href='" . $this->getUrl('images/favicon.ico') . "'/>
        <link rel='icon' type='image/png' sizes='32x32' href='" . $this->getUrl('images/favicon-32x32.png') . "'>
        <link rel='icon' type='image/png' sizes='16x16' href='" . $this->getUrl('images/favicon-16x16.png') . "'>
        <div>
            <img src='" . $this->getUrl("images/RCPro_Logo.svg") . "' width='500px'></img>
            <br>
            <nav style='margin-top:20px;'><ul class='nav nav-tabs rcpro-nav'>
                <li class='nav-item'>
                    <a class='nav-link " . ($page === "Projects" ? "active" : "") . "' aria-current='page' href='" . $this->getUrl("src/cc_projects.php") . "'>
                    <i class='fas fa-briefcase'></i>
                    Projects</a>
                </li>
                <li class='nav-item'>
                    <a class='nav-link " . ($page === "Participants" ? "active" : "") . "' href='" . $this->getUrl("src/cc_participants.php") . "'>
                    <i class='fas fa-users-cog'></i>
                    Participants</a>
                </li>
                <li class='nav-item'>
                    <a class='nav-link " . ($page === "Staff" ? "active" : "") . "' href='" . $this->getUrl("src/cc_staff.php") . "'>
                    <i class='fas fa-users'></i>
                    Staff</a>
                </li>
                <li class='nav-item'>
                    <a class='nav-link " . ($page === "Logs" ? "active" : "") . "' href='" . $this->getUrl("src/cc_logs.php") . "'>
                    <i class='fas fa-list'></i>
                    Logs</a>
                </li>
        ";

        $header .= "</ul></nav>
        </div><hr style='margin-top:0px;'>";
        echo $header;
    }



    /**
     * Logs errors thrown during operation
     * 
     * @param string $message
     * @param \Exception $e
     * 
     * @return void
     */
    public function logError(string $message, \Exception $e)
    {
        $params = [
            "error_code"    => $e->getCode(),
            "error_message" => $e->getMessage(),
            "error_file"    => $e->getFile(),
            "error_line"    => $e->getLine(),
            "error_string"  => $e->__toString(),
            "redcap_user"   => USERID
        ];
        if (isset($e->rcpro)) {
            $params = array_merge($params, $e->rcpro);
        }
        $this->log($message, $params);
    }

    /**
     * Gets the full name of REDCap user with given username
     * 
     * @param string $username
     * 
     * @return string|null Full Name
     */
    public function getUserFullname(string $username)
    {
        $SQL = 'SELECT CONCAT(user_firstname, " ", user_lastname) AS name FROM redcap_user_information WHERE username = ?';
        try {
            $result = $this->query($SQL, [$username]);
            return $result->fetch_assoc()["name"];
        } catch (\Exception $e) {
            $this->logError("Error getting user full name", $e);
        }
    }

    /**
     * Make sure settings meet certain conditions.
     * 
     * This is called when a user clicks "Save" in either system or project
     * configuration.
     * 
     * @param array $settings Array of settings user is trying to set
     * 
     * @return string|null if not null, the error message to show to user
     */
    function validateSettings(array $settings)
    {

        $managers = $users = $monitors = array();
        $message = NULL;

        // project-level settings
        if ($this->getProjectId()) {
            if (count($settings["managers"]) > 0) {
                foreach ($settings["managers"] as $manager) {
                    if (in_array($manager, $managers)) {
                        $message = "This user ($manager) is already a manager";
                    }
                    array_push($managers, $manager);
                }
            }
            if (count($settings["users"]) > 0) {
                foreach ($settings["users"] as $user) {
                    if (in_array($user, $users)) {
                        $message = "This user ($user) is already a user";
                    }
                    array_push($users, $user);
                    if (in_array($user, $managers)) {
                        $message = "This user ($user) cannot have multiple roles";
                    }
                }
            }
            if (count($settings["monitors"]) > 0) {
                foreach ($settings["monitors"] as $monitor) {
                    if (in_array($monitor, $monitors)) {
                        $message = "This user ($monitor) is already a monitor";
                    }
                    array_push($monitors, $monitor);
                    if (in_array($monitor, $managers) || in_array($monitor, $users)) {
                        $message = "This user ($monitor) cannot have multiple roles";
                    }
                }
            }
        } else {
            if (isset($settings["warning-time"]) && $settings["warning-time"] <= 0) {
                $message = "The warning time must be a positive number.";
            }
            if (isset($settings["timeout-time"]) && $settings["timeout-time"] <= 0) {
                $message = "The timeout time must be a positive number.";
            }
            if (isset($settings["password-length"]) && $settings["password-length"] < 8) {
                $message = "The minimum password length must be a positive integer greater than or equal to 8.";
            }
            if (isset($settings["login-attempts"]) && $settings["login-attempts"] < 1) {
                $message = "The minimum setting for login attempts is 1.";
            }
            if (isset($settings["lockout-seconds"]) && $settings["lockout-seconds"] < 0) {
                $message = "The minimum lockout duration is 0 seconds.";
            }
        }
        return $message;
    }
}

/**
 * Authorization class
 */
class Auth
{

    public static $APPTITLE;

    function __construct($title = null)
    {
        self::$APPTITLE = $title;
    }

    public function init()
    {
        $session_id = $_COOKIE["survey"] ?? $_COOKIE["PHPSESSID"];
        if (!empty($session_id)) {
            session_id($session_id);
        } else {
            $this->createSession();
        }
        session_start();
    }

    public function createSession()
    {
        \Session::init();
        $this->set_csrf_token();
    }

    public function set_csrf_token()
    {
        $_SESSION[self::$APPTITLE . "_token"] = bin2hex(random_bytes(24));
    }

    public function get_csrf_token()
    {
        return $_SESSION[self::$APPTITLE . "_token"];
    }

    public function validate_csrf_token(string $token)
    {
        return hash_equals($this->get_csrf_token(), $token);
    }

    // --- THESE DEAL WITH SESSION VALUES --- \\

    // TESTS 
    public function is_logged_in()
    {
        return isset($_SESSION[self::$APPTITLE . "_loggedin"]) && $_SESSION[self::$APPTITLE . "_loggedin"] === true;
    }

    public function is_survey_url_set()
    {
        return isset($_SESSION[self::$APPTITLE . "_survey_url"]);
    }

    public function is_survey_link_active()
    {
        return $_SESSION[self::$APPTITLE . "_survey_link_active"];
    }

    // GETS

    public function get_survey_url()
    {
        return $_SESSION[self::$APPTITLE . "_survey_url"];
    }

    public function get_participant_id()
    {
        return $_SESSION[self::$APPTITLE . "_participant_id"];
    }

    public function get_username()
    {
        return $_SESSION[self::$APPTITLE . "_username"];
    }

    // SETS

    public function deactivate_survey_link()
    {
        unset($_SESSION[self::$APPTITLE . "_survey_link_active"]);
    }

    public function set_survey_url($url)
    {
        $_SESSION[self::$APPTITLE . "_survey_url"] = $url;
    }

    public function set_survey_active_state($state)
    {
        $_SESSION[self::$APPTITLE . "_survey_link_active"] = $state;
    }

    public function set_login_values($participant)
    {
        $_SESSION["username"] = $participant["rcpro_username"];
        $_SESSION[self::$APPTITLE . "_participant_id"] = $participant["log_id"];
        $_SESSION[self::$APPTITLE . "_username"] = $participant["rcpro_username"];
        $_SESSION[self::$APPTITLE . "_email"] = $participant["email"];
        $_SESSION[self::$APPTITLE . "_fname"] = $participant["fname"];
        $_SESSION[self::$APPTITLE . "_lname"] = $participant["lname"];
        $_SESSION[self::$APPTITLE . "_loggedin"] = true;
    }
}


class ProjectSettings
{
    public static $module;

    function __construct($module)
    {
        self::$module = $module;
    }

    public function getTimeoutWarningMinutes()
    {
        $result = self::$module->getSystemSetting("warning-time");
        if (!floatval($result)) {
            // DEFAULT TO 1 MINUTE IF NOT SET
            $result = 1;
        }
        return $result;
    }

    public function getTimeoutMinutes()
    {
        $result = self::$module->getSystemSetting("timeout-time");
        if (!floatval($result)) {
            // DEFAULT TO 5 MINUTES IF NOT SET
            $result = 5;
        }
        return $result;
    }

    public function getPasswordLength()
    {
        $result = self::$module->getSystemSetting("password-length");
        if (!intval($result)) {
            // DEFAULT TO 8 CHARACTERS IF NOT SET
            $result = 8;
        }
        return $result;
    }

    public function getLoginAttempts()
    {
        $result = self::$module->getSystemSetting("login-attempts");
        if (!intval($result)) {
            // DEFAULT TO 3 ATTEMPTS IF NOT SET
            $result = 3;
        }
        return $result;
    }

    public function getLockoutDurationSeconds()
    {
        $result = self::$module->getSystemSetting("lockout-seconds");
        if (!intval($result)) {
            // DEFAULT TO 300 SECONDS IF NOT SET
            $result = 300;
        }
        return $result;
    }
}

class Instrument
{
    public static $module;
    public static $instrument_name;
    public $dd;
    public $username;
    public $email;
    public $fname;
    public $lname;

    function __construct($module, $instrument_name)
    {
        self::$module = $module;
        self::$instrument_name = $instrument_name;
        $this->dd = $this->getDD();
        $this->username = $this->getUsernameField();
        if (isset($this->username)) {
            $this->email = $this->getEmailField();
            $this->fname = $this->getFirstNameField();
            $this->lname = $this->getLastNameField();
        }
    }

    function getDD()
    {
        $json = \REDCap::getDataDictionary("json", false, null, [self::$instrument_name], false);
        return json_decode($json, true);
    }

    function getUsernameField()
    {
        foreach ($this->dd as $field) {
            if (
                strpos($field["field_annotation"], "@RCPRO-USERNAME") !== FALSE
                && $field["field_type"] === "text"
            ) {
                return $field["field_name"];
            }
        }
    }

    function getEmailField()
    {
        foreach ($this->dd as $field) {
            if (
                strpos($field["field_annotation"], "@RCPRO-EMAIL") !== FALSE
                && $field["field_type"] === "text"
                && $field["text_validation_type_or_show_slider_number"] === "email"
            ) {
                return $field["field_name"];
            }
        }
    }

    function getFirstNameField()
    {
        foreach ($this->dd as $field) {
            if (
                strpos($field["field_annotation"], "@RCPRO-FNAME") !== FALSE
                && $field["field_type"] === "text"
            ) {
                return $field["field_name"];
            }
        }
    }

    function getLastNameField()
    {
        foreach ($this->dd as $field) {
            if (
                strpos($field["field_annotation"], "@RCPRO-LNAME") !== FALSE
                && $field["field_type"] === "text"
            ) {
                return $field["field_name"];
            }
        }
    }

    function update_form()
    {
        if (isset($this->username)) {
            $rcpro_project_id = self::$module->getProjectIdFromPID(PROJECT_ID);
            $participants = self::$module->getProjectParticipants($rcpro_project_id);
            $options = "<option value=''>''</option>";
            $participants_json = json_encode($participants);
            foreach ($participants as $participant) {
                $inst_username = \REDCap::escapeHtml($participant["rcpro_username"]);
                $inst_email    = \REDCap::escapeHtml($participant["email"]);
                $inst_fname    = \REDCap::escapeHtml($participant["fname"]);
                $inst_lname    = \REDCap::escapeHtml($participant["lname"]);
                $options      .= "<option value='$inst_username' >$inst_username - $inst_fname $inst_lname - $inst_email</option>";
            }
            $replacement =  "<select id='username_selector'>$options</select>";
            self::$module->initializeJavascriptModuleObject();
        ?>
            <script>
                (function($, window, document) {
                    let module = <?= self::$module->getJavascriptModuleObjectName() ?>;
                    let participants_json = '<?= $participants_json ?>';
                    let participants_obj = JSON.parse(participants_json);
                    let participants = Object.values(participants_obj);
                    let empty_participant = {
                        email: "",
                        fname: "",
                        lname: ""
                    };

                    let username_input = $("input[name='<?= $this->username ?>']");
                    username_input.hide();
                    let username_select = $("<?= $replacement ?>")[0];
                    username_input.after(username_select);
                    $('#username_selector').select2({
                            placeholder: 'Select a participant',
                            allowClear: true
                        }).val(username_input.val()).trigger('change')
                        .on("change", (evt) => {
                            let val = evt.target.value;
                            let participant = empty_participant;
                            if (val !== "") {
                                participant = participants.filter((p) => p.rcpro_username === val)[0];
                            }
                            username_input.val(participant.rcpro_username);
                            let logParameters = {
                                rcpro_username: participant.rcpro_username,
                                redcap_user: "<?= USERID ?>"
                            };

                            // If there is an email field, update it
                            <?php if (isset($this->email)) { ?>
                                let email_input = $('input[name="<?= $this->email ?>"]');
                                if (email_input) {
                                    email_input.val(participant.email);
                                }
                            <?php } ?>

                            // If there is a fname field, update it
                            <?php if (isset($this->fname)) { ?>
                                let fname_input = $('input[name="<?= $this->fname ?>"]');
                                if (fname_input) {
                                    fname_input.val(participant.fname);
                                }
                            <?php } ?>

                            // If there is a lname field, update it
                            <?php if (isset($this->lname)) { ?>
                                let lname_input = $('input[name="<?= $this->lname ?>"]');
                                if (lname_input) {
                                    lname_input.val(participant.lname);
                                }
                            <?php } ?>

                            // Log this.
                            module.log("Populated REDCapPRO User Info On Form", logParameters);
                        });

                })(window.jQuery, window, document);
            </script>
<?php
        }
    }
}


class REDCapProException extends \Exception
{
    public $rcpro = NULL;
    public function __construct($rcpro = NULL)
    {
        $this->rcpro = $rcpro;
    }
}

?>