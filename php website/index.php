<?php 
	header('Content-type: application/json');
	// Include database connector
	include 'database_connector.php';
	
	// test connection
	if (isset($_POST['test_connection'])) {
		$data['connection_response'] = "OK";
	}

	// check app key and id
	if (isset($_POST['checkapp'])) {
		$data['valid_app'] = False;
		if (!isset($_POST['app_key']) or empty($_POST['app_key'])) {
			$data['response_message'] = "Invalid app key";
		}elseif (!isset($_POST['app_id']) or empty($_POST['app_id'])) {
			$data['response_message'] = "Invalid app id";
		}else{
			if (checkAppKey($_POST['app_id'], $_POST['app_key'])) {
				if (checkAppKeyExpiration($_POST['app_key'])) {
					$data['response_message'] = "App key expired! Please login";
				}else{
					$data['response_message'] = "Valid app key and id";
					$data['valid_app'] = True;
					$userDetails = getAppUser($_POST['app_id']);
					if(!empty($userDetails) && isset($userDetails['user_name'])){
						$data['username'] = $userDetails['user_name'];
					}else{
						$data['username'] = "";
					}

					$appDetails = getAppDetails($_POST['app_id']);
					if(!empty($appDetails) && isset($appDetails['key_expiration_date'])){
						$data['expiry_date'] = $appDetails['key_expiration_date'];
					}else{
						$data['expiry_date'] = "unknown";
					}
				}
			}else{
				$data['response_message'] = "Invalid app id or key";
			}
		}

		$data['app_id'] = $_POST['app_id'];
		$data['app_key'] = $_POST['app_key'];
	}

	// login user
	if (isset($_POST['login'])) {
		// default login bool
		$data['logged_in'] = False;

		$data['app_key'] = "";
		$data['app_id'] = "";

		// Check username
		if (!isset($_POST['username']) or empty($_POST['username'])) {
			$data['response_message'] = "The username is required";
		// check password
		}elseif (!isset($_POST['password']) or empty($_POST['password'])) {
			$data['response_message'] = "The password is required";
		}else{
			// Check if username and password is correct
			$sql = "SELECT * FROM `user_information` WHERE `user_name` = '".$_POST['username']."' AND `user_password` = '".$_POST['password']."'";
			$query = $dbh->prepare($sql); $query->execute(); 
			$result = $query;
			if ($row = $result->fetch( PDO::FETCH_ASSOC )){
				$data['response_message'] = "Successfully logged in";
				$data['logged_in'] = True;

				if (isset($_POST['app_id']) && !empty($_POST['app_id']) && isset($_POST['app_key'])) {
					// Check if the app key and ID is valid
					if (checkAppKey($_POST['app_id'], $_POST['app_key']) || checkAppId($_POST['app_id'], $row['user_id'])) {
						// renew app key
						$id = $_POST['app_id'];
						if (renewAppKey($id)) {
							$data['app_key'] = getAppKey($id);
						}else{
							$data['app_key'] = "";
						}

						$data['app_id'] = $_POST['app_id'];
					}else{
						$data['app_key'] = createNewApp($_POST['username']);
						$data['app_id'] = getAppId($data['app_key']);
					}
				}else{
					$data['app_key'] = createNewApp($_POST['username']);
					$data['app_id'] = getAppId($data['app_key']);
				}

			}else{
				$data['response_message'] = "Invalid username or password";
			}
		}
	}

	// Register user
	if (isset($_POST['register'])) {
		$data['registered'] = False;

		$data['app_key'] = "";
		$data['app_id'] = "";

		if (!isset($_POST['username']) or empty($_POST['username'])) {
			$data['response_message'] = "The username is required";
		}elseif (!isset($_POST['password']) or empty($_POST['password'])) {
			$data['response_message'] = "The password is required";
		}elseif (!isset($_POST['confirm_password']) or empty($_POST['confirm_password'])) {
			$data['response_message'] = "Please confirm your password";
		}elseif ($_POST['password'] !== $_POST['confirm_password']) {
			$data['response_message'] = "Passwords not matching";
		}else{
			$sql = "SELECT * FROM `user_information` WHERE `user_name` = '".$_POST['username']."'";
			$query = $dbh->prepare($sql); $query->execute(); 
			$result = $query;
			if ($row = $result->fetch( PDO::FETCH_ASSOC )){
				$data['response_message'] = "The user with that username already exists, please select a different username";
			}else{
				$sql = "INSERT INTO `user_information`(`user_id`, `user_name`, `user_password`) VALUES (NULL, :user_name, :user_password)";

		        $query = $dbh->prepare($sql);

		        $query->bindParam('user_name', $_POST['username'], PDO::PARAM_STR );
		        $query->bindParam('user_password', $_POST['password'], PDO::PARAM_STR );

		        $query->execute();

				$data['response_message'] = "Your account was succesfully created";

				$data['registered'] = True;

				$data['app_key'] = createNewApp($_POST['username']);
				$data['app_id'] = getAppId($data['app_key']);
			}
		}
	}

	// Create new app ID/KEY
	function createNewApp($username)
	{
		global $dbh;
		// get user id
        $sql = "SELECT * FROM `user_information` WHERE `user_name` = '".$username."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			$id = $row['user_id'];
			$expiry_date = date('Y-m-d H:i:s', strtotime('+1 day', time()));

			// Generate random app key
			$application_key = random_str(12);
			// Check if the key exists
			while (view_app_key($application_key) == "true") {
	    		$application_key = random_str(12);
	    	}
	    	// insert new app key and id
	    	$sql = "INSERT INTO `applications`(`application_id`, `user_id`, `application_key`, `key_expiration_date`) VALUES (NULL, :user_id, :application_key, :key_expiration_date)";

	        $query = $dbh->prepare($sql);

	        $query->bindParam('user_id', $id, PDO::PARAM_STR );
	        $query->bindParam('application_key', $application_key, PDO::PARAM_STR );
	        $query->bindParam('key_expiration_date', $expiry_date, PDO::PARAM_STR );

	        $query->execute();

	        // return new app key
	        return $application_key;
	    }else{
	    	return "";
	    }
	}

	// get app ID from key
	function getAppId($key)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_key` = '".$key."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			return $row['application_id'];
		}else{
			return "";
		}
	}

	// get app key from ID
	function getAppKey($id)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_id` = '".$id."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			return $row['application_key'];
		}else{
			return "";
		}
	}

	// check app key from id
	function checkAppKey($id, $key)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_id` = '".$id."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			if ($row['application_key'] == $key) {
				return true;
			}else{
				return false;
			}
		}else{
			return false;
		}
	}

	// check if app id belongs to user id
	function checkAppId($app_id, $user_id)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_id` = '".$app_id."' AND `user_id` = '".$user_id."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			return true;
		}else{
			return false;
		}
	}

	// check app key expiration
	function checkAppKeyExpiration($key)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_key` = '".$key."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			$current_date = date("Y-m-d H:i:s");
			$expiration_date = $row['key_expiration_date'];

			if (strtotime($current_date) > strtotime($expiration_date)) {
				return true;
			}else{
				return false;
			}
		}else{
			return true;
		}
	}

	// renew app key
	function renewAppKey($id)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_id` = '".$id."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){

			$expiry_date = date('Y-m-d H:i:s', strtotime('+1 day', time()));

			// Generate random app key
			$application_key = random_str(12);
			// Check if the key exists
			while (view_app_key($application_key) == "true") {
	    		$application_key = random_str(12);
	    	}

			$sql = "UPDATE `applications` SET `application_key`= :application_key,`key_expiration_date`= :key_expiration_date WHERE `application_id`= :application_id";

	        $query = $dbh->prepare($sql);

	        $query->bindParam('application_key', $application_key, PDO::PARAM_STR );
	        $query->bindParam('key_expiration_date', $expiry_date, PDO::PARAM_STR );
	        $query->bindParam('application_id', $id, PDO::PARAM_STR );

	        $query->execute();

	        return true;
		}else{
			return false;
		}
	}

	// get app user from ID
	function getAppUser($id)
	{
		global $dbh;
		

		$sql = "SELECT * FROM `applications` WHERE `application_id` = '".$id."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			$userId = $row['user_id'];
			$sql = "SELECT * FROM `user_information` WHERE `user_id` = '".$userId."'";
			$query = $dbh->prepare($sql); $query->execute(); 
			$result = $query;
			if ($row = $result->fetch( PDO::FETCH_ASSOC )){
				return $row;
			}
			$row = [];
			return $row;
		}
	}

	// get app details
	function getAppDetails($id)
	{
		global $dbh;

		$sql = "SELECT * FROM `applications` WHERE `application_id` = '".$id."'";
		$query = $dbh->prepare($sql); $query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			return $row;
		}else{
			$row = [];
			return $row;
		}
	}

	// Generate random strings
	function random_str(
        int $length = 64,
        string $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
    ): string {
        if ($length < 1) {
            throw new \RangeException("Length must be a positive integer");
        }
        $pieces = [];
        $max = mb_strlen($keyspace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $pieces []= $keyspace[random_int(0, $max)];
        }
        return implode('', $pieces);
    }

    // view if app key already exists
    function view_app_key($link)
    {
    	global $dbh;
    	$sql = "SELECT * FROM `applications` WHERE `application_key`='".$link."'";
    	$query = $dbh->prepare($sql); 
    	$query->execute(); 
		$result = $query;
		if ($row = $result->fetch( PDO::FETCH_ASSOC )){
			return "true";
    	}else{
    		return "false";
    	}

    }

	echo json_encode($data);
?>