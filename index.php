<?php

class Api {
  private $version = "1.4.0";
  private $debug = false;
  private $debug_log = array();
  private $timestart;
  private $databases = array();
  private $log_path;
  private $update_url = "https://raw.githubusercontent.com/Baghe/baghe-frapi/refs/heads/main/index.php";

  public function __construct($params) {
    // Set debug mode
    if (!empty($params["env"]) && $params["env"] == "test") {
      $this->debug = true;
    }

    // Initialize
    self::debug_log("--------------------------------- START ---------------------------------");
    self::debug_log("API version: " . $this->version);
    $this->timestart = microtime(true);

    // Check for .api folder
    if (!file_exists(dirname(__FILE__) . "/.api")) {
      self::debug_log("Creating .api folder");
      mkdir(dirname(__FILE__) . "/.api", 0777, true);
    }

    // Check for updates
    if (empty($params["skip-update"])) {
      $this->check_updates();
    } else {
      self::debug_log("Update check skipped");
    }

    // Define databases
    if (!empty($params["databases"])) {
      foreach ($params["databases"] as $key => $value) {
        self::debug_log("Defining database: {$key}");
        $debug_log = function ($string) {
          self::debug_log("[DB] {$string}", "");
        };
        $this->databases[$key] = new Database($value, $debug_log);
      }
    }

    // Set log path
    if (!empty($params["log_path"])) {
      $params["log_path"] = str_replace("\\", "/", realpath($params["log_path"]));
      self::debug_log("Log path: {$params["log_path"]}");
      $this->log_path = $params["log_path"];
      $this->log_init();
    }
  }

  private function check_updates() {
    $lastCheck = dirname(__FILE__) . "/.api/lastcheck";
    if (!file_exists($lastCheck) || (time() - filemtime($lastCheck)) > 3600) {
      self::debug_log("Checking for updates");
      self::debug_log("Last check: " . (file_exists($lastCheck) ? date("Y-m-d H:i:s", filemtime($lastCheck)) : "Never"));

      $current = file_get_contents(__FILE__);
      $latest = file_get_contents($this->update_url);
      if ($current != $latest) {
        self::debug_log("Update found");
        self::debug_log("Current: " . md5($current));
        self::debug_log("Latest: " . md5($latest));
        file_put_contents(__FILE__, $latest);
        self::debug_log("Updated");
      } else {
        self::debug_log("No updates found");
      }
    } else {
      self::debug_log("Update check skipped");
    }
    touch($lastCheck);
  }

  /* INPUT/OUTPUT */
  public function input($key, $default = null) {
    $entityBody = file_get_contents("php://input");
    if (empty($entityBody)) {
      $entityBody = json_encode(filter_input_array(INPUT_GET));
    }
    if (!empty($entityBody)) {
      $postData = json_decode($entityBody, true);
      if (isset($postData[$key])) {
        return $postData[$key];
      }
    }
    return $default;
  }

  public function output($result, $data, $error = null) {
    self::debug_log("--------------------------------- END ---------------------------------");
    $output = array(
      "Result" => $result,
      "Data" => $data,
      "Error" => $error,
      "Datetime" => date("Y-m-d H:i:s"),
      "Version" => $this->version
    );
    if ($this->debug) {
      $output["Debug"] = array(
        "Elapsed" => round(microtime(true) - $this->timestart, 4),
        "Memory" => memory_get_usage(true),
        "Peak" => memory_get_peak_usage(true),
        "Log" => $this->debug_log
      );
    }
    ob_clean();
    if (!empty($_SERVER["HTTP_ORIGIN"])) {
      header("Access-Control-Allow-Origin: {$_SERVER["HTTP_ORIGIN"]}");
      header("Access-Control-Allow-Credentials: true");
    } else {
      header('Access-Control-Allow-Origin: *');
    }
    header("Content-type: application/json; charset=utf-8");
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    die();
  }

  /* DATABASE */
  public function db($name = null) {
    if (!$name) {
      $name = count($this->databases) ? array_keys($this->databases)[0] : null;
    }
    if (isset($this->databases[$name])) {
      return $this->databases[$name];
    }
    trigger_error("[DATABASE] Database not found: {$name}", E_USER_ERROR);
    header("HTTP/1.0 500 Internal Server Error");
    die();
  }

  /* LOGGING */
  protected function debug_log($string, $space = " ") {
    if ($this->debug) {
      $this->debug_log[] = "[" . date("H:i:s") . "]{$space}{$string}";
    }
  }

  public function log($data, $exit = false) {
    if (empty($this->log_path)) {
      return;
    }
    self::debug_log("[USER] {$data}", "");
    return self::log_append("USER", null, null, $data, $exit);
  }

  private function log_init() {
    if (empty($this->log_path)) {
      return;
    }
    if (!is_dir($this->log_path)) {
      mkdir($this->log_path, 0777, true);
    }
    register_shutdown_function(function () {
      $this->log_override_fatal();
    });
    set_error_handler(function ($num, $message, $file, $line, $context = null) {
      $this->log_override_error($num, $message, $file, $line, $context);
    });
    set_exception_handler(function ($e) {
      $this->log_override_exception($e);
    });
  }

  private function log_override_fatal() {
    $error = error_get_last();
    if (isset($error["type"]) && $error["type"] == E_ERROR) {
      self::debug_log("[ERROR] {$error["message"]} in {$error["file"]} on line {$error["line"]}");
      $this->log_append("ERROR", $error["file"], $error["line"], $error["message"], true);
    }
  }

  private function log_override_error($num, $message, $file, $line, $context = null) {
    self::debug_log("[ERROR] {$message} in {$file} on line {$line}");
    $this->log_append("ERROR", $file, $line, $message);
  }

  private function log_override_exception($e) {
    self::debug_log("[ERROR] {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");
    $this->log_append("ERROR", $e->getFile(), $e->getLine(), $e->getMessage(), true);
  }

  private function log_append($type, $file, $line, $message, $exit = false) {
    $path = $this->log_path . "/" . date("Y-m-d") . ".log";
    $line = date("H:i:s") . "|{$type}|{$file}|{$line}|" . str_replace(array("\n\r", "\n", "\r"), "<%n>", trim($message));
    file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
    if ($exit) {
      exit;
    }
  }
}

class Database {
  private $connection;
  private $database;
  private $debug_log;

  public function __construct($database, $debug_log = null) {
    $this->debug_log = $debug_log;
    $this->database = $database;
  }

  private function log($string) {
    if (is_callable($this->debug_log)) {
      call_user_func($this->debug_log, $string);
    }
  }

  public function connect() {
    try {
      if (!isset($this->connection)) {
        self::log("Connecting to {$this->database["hostname"]}");
        $port = 3306;
        if (!empty($this->database["port"])) {
          $port = $this->database["port"];
        }
        $this->connection = new mysqli($this->database["hostname"], $this->database["username"], $this->database["password"], $this->database["database"], $port);

        usleep(200 * 1000);
        if ($this->connection) {
          if (isset($this->database["params"])) {
            if (isset($this->database["params"]["charset"]) && $this->database["params"]["charset"]) {
              self::log("Setting custom charset: {$this->database["params"]["charset"]}");
              $this->connection->set_charset($this->database["params"]["charset"]);
            } else {
              $this->connection->set_charset("utf8");
            }
            if (isset($this->database["params"]["sql_mode"])) {
              self::log("Setting custom sql_mode: {$this->database["params"]["sql_mode"]}");
              $this->connection->query("SET sql_mode = '{$this->database["params"]["sql_mode"]}';");
            }
            if (isset($this->database["params"]["time_zone"])) {
              self::log("Setting custom time_zone: {$this->database["params"]["time_zone"]}");
              $this->connection->query("SET time_zone = '{$this->database["params"]["time_zone"]}';");
            }
          } else {
            $this->connection->set_charset("utf8");
          }
          $this->connection->query("SET SESSION wait_timeout=600;");
        }
      }
    } catch (Exception $ex) {
      self::serverDown($ex->getMessage());
      self::log("Connection error: {$ex->getMessage()}");
      trigger_error("[DATABASE] connect: " . $ex->getMessage(), E_USER_WARNING);
    }
    return $this->connection;
  }

  public function query($query, $return_id = false) {
    try {
      $conn = $this->connect();
      if ($conn) {
        self::log("Query: {$query}");
        $result = $conn->query($query);
        if ($result) {
          if ($return_id) {
            return $conn->insert_id;
          }
          return $result;
        } else {
          self::log("Query error: {$conn->error}");
          self::serverDown($conn->error);
          trigger_error("[DATABASE] query: " . $conn->error, E_USER_WARNING);
          trigger_error("[{$query}]", E_USER_WARNING);
        }
      }
    } catch (Exception $ex) {
      self::log("Query exception: {$ex->getMessage()}");
      self::serverDown($ex->getMessage());
      trigger_error("[DATABASE] query: " . $ex->getMessage(), E_USER_WARNING);
      trigger_error("[{$query}]", E_USER_WARNING);
    }
    return false;
  }

  public function select($query, $firstRowOnly = false, $field = null) {
    $rows = array();
    try {
      $conn = $this->connect();
      if ($conn) {
        self::log("Select: {$query}");
        $result = $conn->query($query);
        if ($result) {
          while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
          }
        } else {
          self::log("Query error: {$conn->error}");
          self::serverDown($conn->error);
          trigger_error("[DATABASE] select: " . $conn->error, E_USER_WARNING);
          trigger_error("[{$query}]", E_USER_WARNING);
        }
      }
    } catch (Exception $ex) {
      self::log("Query exception: {$ex->getMessage()}");
      self::serverDown($ex->getMessage());
      trigger_error("[DATABASE] select: " . $ex->getMessage(), E_USER_WARNING);
      trigger_error("[{$query}]", E_USER_WARNING);
    }
    if ($firstRowOnly) {
      if (count($rows)) {
        if ($field) {
          if (isset($rows[0][$field])) {
            return $rows[0][$field];
          } else {
            return null;
          }
        }
        return $rows[0];
      } else {
        return null;
      }
    }
    return $rows;
  }

  public function insert($table, $values, $returnId = false, $onDuplicate = null) {
    $query = null;
    try {
      if (count($values)) {
        $query = "INSERT INTO {$table} (" . implode(", ", array_keys($values)) . ") "
          . "VALUES (" . implode(", ", $values) . ")"
          . ($onDuplicate ? " ON DUPLICATE KEY UPDATE {$onDuplicate}" : "") . ";";

        $conn = $this->connect();
        if ($conn) {
          self::log("Insert: {$query}");
          $result = $conn->query($query);
          if ($result) {
            if ($returnId) {
              return $conn->insert_id;
            }
            return true;
          } else {
            self::log("Insert error: {$conn->error}");
            self::serverDown($conn->error);
            trigger_error("[DATABASE] Insert: " . $conn->error, E_USER_WARNING);
            trigger_error("[DATABASE] Query:  |{$query}|", E_USER_WARNING);
            trigger_error("[DATABASE] Values: |" . json_encode($values) . "|", E_USER_WARNING);
          }
        }
      }
    } catch (Exception $ex) {
      self::log("Insert exception: {$ex->getMessage()}");
      self::serverDown($ex->getMessage());
      trigger_error("[DATABASE] insert: " . $ex->getMessage(), E_USER_WARNING);
      if ($query) {
        trigger_error("[{$query}]", E_USER_WARNING);
      }
    }
    return false;
  }

  public function insertMultiple($table, $columns, $values, $onDuplicate = null, $returnQuery = false) {
    $query = null;
    try {
      if (count($values)) {
        $valuesRows = array();
        foreach ($values as $row) {
          $valuesRows[] = "(" . implode(", ", $row) . ")";
        }

        $query = "INSERT INTO {$table} (" . implode(", ", $columns) . ") "
          . "VALUES " . implode(", ", $valuesRows) . ""
          . ($onDuplicate ? " ON DUPLICATE KEY UPDATE {$onDuplicate}" : "") . ";";
        if ($returnQuery) {
          return $query;
        }

        $conn = $this->connect();
        if ($conn) {
          self::log("InsertMultiple: {$query}");
          $result = $conn->query($query);
          if ($result) {
            return true;
          } else {
            self::log("InsertMultiple error: {$conn->error}");
            self::serverDown($conn->error);
            trigger_error("[DATABASE] insertMultiple: " . $conn->error, E_USER_WARNING);
            trigger_error("[{$query}]", E_USER_WARNING);
          }
        }
      }
    } catch (Exception $ex) {
      self::log("InsertMultiple exception: {$ex->getMessage()}");
      self::serverDown($ex->getMessage());
      trigger_error("[DATABASE] insertMultiple: " . $ex->getMessage(), E_USER_WARNING);
      if ($query) {
        trigger_error("[{$query}]", E_USER_WARNING);
      }
    }
    return false;
  }

  public function update($table, $values, $where = null) {
    $query = null;
    try {
      if (count($values)) {
        $query = "UPDATE {$table} SET " . self::updateValues($values) . ($where ? " WHERE {$where}" : "") . ";";
        $conn = $this->connect();
        if ($conn) {
          self::log("Update: {$query}");
          $result = $conn->query($query);
          if ($result) {
            return true;
          } else {
            self::log("Update error: {$conn->error}");
            self::serverDown($conn->error);
            trigger_error("[DATABASE] Update: " . $conn->error, E_USER_WARNING);
            trigger_error("[DATABASE] Query:  |{$query}|", E_USER_WARNING);
            trigger_error("[DATABASE] Values: |" . json_encode($values) . "|", E_USER_WARNING);
          }
        }
      }
    } catch (Exception $ex) {
      self::log("Update exception: {$ex->getMessage()}");
      self::serverDown($ex->getMessage());
      trigger_error("[DATABASE] update: " . $ex->getMessage(), E_USER_WARNING);
      if ($query) {
        trigger_error("[{$query}]", E_USER_WARNING);
      }
    }
    return false;
  }

  private function updateValues($fields, $exclude = array()) {
    $values = array();
    foreach ($fields as $field => $value) {
      if (!in_array($field, $exclude)) {
        $values[] = "{$field} = {$value}";
      }
    }
    return implode(", ", $values);
  }

  public function delete($table, $where = null) {
    $query = null;
    try {
      $query = "DELETE FROM {$table}" . ($where ? " WHERE {$where}" : "") . ";";
      $conn = $this->connect();
      if ($conn) {
        self::log("Delete: {$query}");
        $result = $conn->query($query);
        if ($result) {
          return true;
        } else {
          self::log("Delete error: {$conn->error}");
          self::serverDown($conn->error);
          trigger_error("[DATABASE] Delete: " . $conn->error, E_USER_WARNING);
          trigger_error("[DATABASE] Query:  |{$query}|", E_USER_WARNING);
        }
      }
    } catch (Exception $ex) {
      self::log("Delete exception: {$ex->getMessage()}");
      self::serverDown($ex->getMessage());
      trigger_error("[DATABASE] delete: " . $ex->getMessage(), E_USER_WARNING);
      if ($query) {
        trigger_error("[{$query}]", E_USER_WARNING);
      }
    }
    return false;
  }

  public function error() {
    $conn = $this->connect();
    return $conn->error;
  }

  public function now($format = "Y-m-d H:i:s") {
    return self::q(date($format));
  }

  public function q($value, $nullvalue = null) {
    if (strlen((string) $value) == 0 && $nullvalue === "NULL") {
      return "NULL";
    }
    $conn = $this->connect();
    if ($conn) {
      return "'" . $conn->real_escape_string((string) $value) . "'";
    }
    return null;
  }

  public function startTransaction() {
    $conn = $this->connect();
    if ($conn) {
      self::log("Starting transaction");
      $conn->autocommit(false);
      $conn->begin_transaction();
    }
    return null;
  }

  public function commitTransaction() {
    $conn = $this->connect();
    if ($conn) {
      self::log("Committing transaction");
      $conn->commit();
    }
    return null;
  }

  public function rollbackTransaction() {
    $conn = $this->connect();
    if ($conn) {
      self::log("Rolling back transaction");
      $conn->rollback();
    }
    return null;
  }

  public function onDuplicateValues($fields, $skip = 0) {
    if (is_array($skip)) {
      $fields = array_diff($fields, $skip);
      $skip = 0;
    }
    return implode(", ", array_map(function ($k) {
      return sprintf("%s=VALUES(%s)", $k, $k);
    }, array_slice($fields, $skip)));
  }

  private function serverDown($msg) {
    try {
      if (
        strpos($msg, "server has gone") > -1 ||
        strpos($msg, "such file or directory") > -1 ||
        strpos($msg, "Too many connections") > -1 ||
        strpos($msg, "failed with errno=32 Broken pipe") > -1 ||
        strpos($msg, "Packets out of order") > -1 ||
        strpos($msg, "Couldn't fetch mysqli") > -1
      ) {
        trigger_error("[DATABASE] Connection lost. Die!", E_USER_ERROR);
        header("HTTP/1.0 500 Internal Server Error");
        die();
      }
    } catch (Exception $ex) {
    }
    return false;
  }
}
