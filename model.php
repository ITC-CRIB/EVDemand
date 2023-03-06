<?php
namespace EVDemand;

require_once("agent.php");
require_once("car.php");

class Model {
	// Timestamp
	private float $timestamp;

	// Agents
	private array $agents = [];

	// Recharge behaviors
	private array $rechargeBehaviors = [];

	// Distances (km)
	private array $distances = [];

	// Start times (h)
	private array $startTimes = [];

	// Car models
	private array $carModels = [];

	// Logs
	private $log;

	// Cache
	private array $cache = [];

	// Options
	private array $opts = [];

	// Default options
	const DEFAULT_OPTIONS = [
		// Data path
		"dataPath" => "",
		// Seed for the random number generator
		"randomSeed" => NULL,
		// Time step to use for the model run (s)
		"timespan" => 6 * 60,
		// Default travel speed (km/h)
		"defaultSpeed" => 40.0,
		// Distance conversion factor while reading the distance matrix
		"distanceFactor" => 1.0,
		// Date format to use for the logs
		"dateFormat" => "Y-m-d H:i:s",
		// Maximum number of state logs to keep in the history for each agent
		"maxAgentLogs" => 50,
		// Maximum number of states to keep in the history for each agent
		"maxAgentStates" => 5,
		// Initial charge percentage (%)
		"initialCharge" => 100.0,
		// Initial charge assignment method
		"initialChargeMethod" => "fixed",
		// Minimum initial charge percentage (%)
		"minInitialCharge" => 50.0,
		// Code of the default car
		"defaultCar" => NULL,
		// Code of the default recharge behavior
		"defaultRechargeBehavior" => NULL,
	];

	/**
	 * Returns current timestamp
	 *
	 * @return float Current timestamp
	 */
	public function getTimestamp() {
		return $this->timestamp;
	}

	/**
	 * Returns model option
	 *
	 * @param string $name Option name
	 *
	 * @return mixed Option value
	 *
	 * @throws Exception If invalid option name.
	 */
	public function getOption($name) {
		if (array_key_exists($name, $this->opts)) {
			return $this->opts[$name];
		}
		throw new \Exception("Invalid option name {$name}");
	}

	/**
	 * Tabulates data
	 *
	 * Options:
	 * - array cols Columns to be tabulated
	 *
	 * @param array $data Data
	 * @param array $opts Options (optional)
	 *
	 * @return string Tabulated data
	 */
	public static function tabulate($data, $opts = []) {
		if (is_array($opts["cols"])) {
			$cols = $opts["cols"];
		}
		else if ($opts["cols"] == "scan") {
			$cols = [];
			foreach ($data as $row) {
				$cols += array_keys($row);
			}
		}
		else {
			$cols = array_keys(reset($data));
		}
		$max = [];
		foreach ($cols as $col) {
			$max[$col] = strlen($col);
		}
		foreach ($data as $id => $row) {
			if ($opts["process"]) {
				$data[$id] = $row = call_user_func($opts["process"], $row);
			}
			foreach ($row as $col => $val) {
				$len = strlen($val);
				if ($len > $max[$col]) $max[$col] = $len;
			}
		}
		$out = "";
		foreach ($cols as $col) {
			$out .= "| " . str_pad($col, $max[$col], " ", STR_PAD_RIGHT) . " ";
		}
		$out .= "|\n";
		foreach ($cols as $col) {
			$out .= "|:" . str_repeat("-", $max[$col]) . "-";
		}
		$out .= "|\n";
		foreach ($data as $row) {
			foreach ($cols as $col) {
				$out .= "| " . str_pad($row[$col], $max[$col], " ", STR_PAD_RIGHT) . " ";
			}
			$out .= "|\n";
		}
		return $out;
	}

	/**
	 * Logs outputs
	 *
	 * @param mixed ...$outs Outputs
	 */
	public function log(...$outs) {
		foreach ($outs as $out) {
			if (is_array($out)) {
				$str = "";
				foreach ($out as $key => $val) {
					$str .= (empty($str) ? "" : ", ") . $key . ": " . $val;
				}
			}
			else if (is_string($out)) {
				$str = rtrim($out);
			}
			else {
				$str = $out;
			}
			$str .= "\n";
			$this->log .= $str;
			echo $str;
		}
	}

	/**
	 * Loads matrix data
	 *
	 * Matrix data:
	 * - array cols Column name and index pairs
	 * - array rows Row name and index pairs
	 * - array data Data

	 * @param string $filename File name
	 * @param array $opts Options (optional)
	 *
	 * @return array Data matrix
	 *
	 * @throws Exception If cannot open file.
	 */
	public function loadMatrixData($filename, array $opts = []) {
		$file = @fopen($this->getOption("dataPath") . "/" . $filename, "r");
		if ($file === FALSE) {
			throw new \Exception("Cannot open file {$filename}");
		}
		$cols = [];
		$rows = [];
		$data = [];
		$index = NULL;
		while ($row = fgetcsv($file)) {
			if (empty($cols)) {
				array_shift($row);
				foreach ($row as $index => $val) {
					$cols[$val] = $index;
				}
				$index = 0;
				continue;
			}
			$rows[array_shift($row)] = $index++;
			if (isset($opts["factor"])) {
				foreach ($row as &$val) {
					$val *= $opts["factor"];
				}
			}
			$data[] = $row;
		}
		fclose($file);
		return [
			"cols" => $cols,
			"rows" => $rows,
			"data" => $data
		];
	}

	/**
	 * Returns value from the data matrix for the specified column and row
	 *
	 * @param array $matrix Data matrix
	 * @param mixed $col Column name
	 * @param mixed $row Row name
	 *
	 * @return mixed Data value
	 *
	 * @throws Exception If invalid column name.
	 * @throws Exception If invalid row name.
	 */
	public function getMatrixValue($matrix, $col, $row) {
		if (!isset($matrix["cols"][$col])) {
			throw new \Exception("Invalid column {$col}");
		}
		if (!isset($matrix["rows"][$row])) {
			throw new \Exception("Invalid row {$row}");
		}
		return $matrix["data"][$matrix["rows"][$row]][$matrix["cols"][$col]];
	}

	/**
	 * Loads data
	 *
	 * @param string $filename File name
	 * @param array $data_types Data types
	 * @param string $key Key column name (optional)
	 * @param callable $process Row processor (optional)
	 *
	 * @return array Data
	 *
	 * @throws Exception If invalid data row.
	 * @throws Exception If invalid value column.
	 * @throws Exception If invalid maximum value column.
	 * @throws Exception If invalid column name.
	 * @throws Exception if no value.
	 */
	protected function loadData($filename, $data_types = [], $key = NULL, $process = NULL) {
		$file = fopen($this->getOption("dataPath") . "/" . $filename, "r");
		$cols = [];
		$i = 0;
		$rows = [];
		while ($row = fgetcsv($file)) {
			if (empty($cols)) {
				$cols = $row;
				continue;
			}
			$i++;
			if (count($row) != count($cols)) {
				fclose($file);
				throw new \Exception("Invalid data row {$i}");
			}
			$row = array_combine($cols, $row);
			if ($data_types) {
				foreach ($data_types as $col => $type) {
					if (is_array($type)) {
						list($type, $nullable, $min, $max) = $type;
						if (is_string($min)) {
							if (!isset($row[$min])) {
								throw new \Exception("Invalid minimum value column {$min}");
							}
							$min = $row[$min];
						}
						if (is_string($max)) {
							if (!isset($row[$max])) {
								throw new \Exception("Invalid maximum value column {$max}");
							}
							$max = $row[$max];
						}
					}
					else {
						$nullable = TRUE;
						$min = $max = NULL;
					}
					if (!in_array($col, $cols)) {
						throw new \Exception("Invalid column name {$col}");
					}
					$val = trim($row[$col]);
					if ($val === "") {
						if (!$nullable) {
							throw new \Exception("No value (Line {$i}:{$col})");
						}
						$val = NULL;
					}
					else switch ($type) {
						case "int":
							if (preg_match('/^0+$/', $val)) {
								$val = 0;
							}
							else {
								$val = intval($val);
								if ($val == FALSE) {
									throw new \Exception("Invalid integer value {$row[$col]} (Line {$i}:{$col})");
								}
							}
							break;
						case "float":
							if (preg_match('/^(0+|(0+)?\.(0+)?)$/', $val)) {
								$val = 0.0;
							}
							else {
								$val = floatval($val);
								if ($val == FALSE) {
									throw new \Exception("Invalid float value {$row[$col]} (Line {$i}:{$col})");
								}
							}
							break;
						default:
							// NOP
							break;
					}
					if (isset($val) && isset($min)) {
						if (is_array($min)) {
							if (!in_array($val, $min)) {
								throw new \Exception("Invalid value {$row[$col]} (Line {$i}:{$col})");
							}
						}
						else if ($val < $min) {
							throw new \Exception("Invalid value {$row[$col]} (Line {$i}:{$col})");
						}
					}
					if (isset($val) && isset($max) && $val > $max) {
						throw new \Exception("Invalid value {$row[$col]} (Line {$i}:{$col})");
					}
					$row[$col] = $val;
				}
			}
			if (isset($process)) {
				$row = call_user_func($process, $row, $i);
				if (!is_array($row)) {
					throw new \Exception("Invalid processing result (Line {$i})");
				}
			}
			if (isset($key)) {
				$val = $row[$key];
				if (!isset($val)) {
					throw new \Exception("Empty key {$key} (Line {$i})");
				}
				if (isset($rows[$val])) {
					throw new \Exception("Duplicate key {$key}:{$val} (Line {$i})");
				}
				$rows[$val] = $row;
			}
			else {
				$rows[] = $row;
			}
		}
		fclose($file);
		return $rows;
	}

	/**
	 * Loads distances matrix
	 *
	 * Distances are loaded from distances.csv.
	 * Distance values can be corrected by using the `distanceFactor` option.
	 */
	protected function loadDistances() {
		$this->log("Loading distances...");
		$this->distances = $this->loadMatrixData(
			"distances.csv",
			["factor" => $this->getOption("distanceFactor")]
		);
		$this->log(sprintf("%d x %d distances are loaded.", $this->distances["cols"], $this->distances["rows"]));
	}

	/**
	 * Returns distance between two locations
	 *
	 * @param string $from Start location
	 * @param string $to Destination location
	 *
	 * @return float Distance between the location (km)
	 */
	public function getDistance($from, $to) {
		try {
			return $this->getMatrixValue($this->distances, $from, $to);
		}
		catch (\Exception $Exception) {
			return $this->getMatrixValue($this->distances, $to, $from);
		}
	}

	/**
	 * Loads recharge behaviors
	 *
	 * Data columns:
	 * - string code Recharge behavior code
	 * - float charge Charge percentage (0-100)
	 * - float percentage Recharge desire percentage (0-100)
	 *
	 * Recharge behaviors are loaded from recharge_behaviors.csv.
	 *
	 * @throws Exception If no recharge behaviors.
	 */
	protected function loadRechargeBehaviors() {
		$this->log("Loading recharge behaviors...");
		$rows = $this->loadData(
			"recharge_behaviors.csv",
			[
				"code" => ["string", FALSE],
				"charge" => ["float", FALSE, 0, 100],
				"percentage" => ["float", FALSE, 0, 100],
			],
		);
		$data = [];
		foreach ($rows as $row) {
			$data[$row["code"]][$row["charge"]] = $row["percentage"];
		}
		foreach ($data as $code => $series) {
			if (!isset($series[0])) $series[0] = 0;
			if (!isset($series[100])) $series[100] = 0;
			ksort($series);
			$data[$code] = $series;
		}
		if (empty($data)) {
			throw new \Exception("No recharge behaviors");
		}
		$this->rechargeBehaviors = $data;
		$this->log(sprintf("%d recharge behaviors are loaded.", count($this->rechargeBehaviors)));
	}

	/**
	 * Returns recharge desire percentage for the given charge percentage
	 *
	 * @param string $code Recharge behavior code
	 * @param float $charge Charge percentage (%)
	 *
	 * @return float Recharge desire percentage (%)
	 *
	 * @throws Exception If invalid recharge behavior code.
	 * @throws Exception If invalid charge percentage.
	 */
	public function getRechargeDesire($code, $charge) {
		if (empty($this->rechargeBehaviors[$code])) {
			throw new \Exception("Invalid recharge behavior code {$code}");
		}
		if ($charge < 0 || $charge > 100) {
			throw new \Exception("Invalid charge percentage {$charge}");
		}
		foreach ($this->rechargeBehaviors[$code] as $percentage => $desire) {
			if ($percentage == $charge) {
				return $desire;
			}
			if ($percentage > $charge) {
				$toPercent = $percentage;
				$toDesire = $desire;
				break;
			}
			$fromPercent = $percentage;
			$fromDesire = $desire;
		}
		return (
			(($toPercent - $charge) * $fromDesire + ($charge - $fromPercent) * $toDesire) /
			($toPercent - $fromPercent)
		);
	}

	/**
	 * Loads car models
	 *
	 * Data columns:
	 * - string code Car model code
	 * - string brand Brand
	 * - string model Model
	 * - float capacity Maximum charge capacity (kWh)
	 * - float range Maximum range when at full charge (km)
	 * - float recharge Recharge time from zero to full (h)
	 * - float discharge Idle discharge rate (%/h)
	 *
	 * Car models are loaded from cars.csv.
	 *
	 * @throws Exception If no car models.
	 */
	protected function loadCarModels() {
		$this->log("Loading car models...");
		$rows = $this->loadData(
			"cars.csv",
			[
				"code" => ["string", FALSE],
				"brand" => ["string", FALSE],
				"model" => ["string", FALSE],
				"capacity" => ["float", FALSE, 0],
				"range" => ["float", FALSE, 0],
				"recharge" => ["float", FALSE, 0],
				"idle_discharge" => ["float", FALSE, 0],
			],
			"code"
		);
		if (empty($rows)) {
			throw new \Exception("No car models");
		}
		$this->carModels = [];
		foreach ($rows as $row) {
			$this->carModels[$row["code"]] = new Car(
				$row["brand"],
				$row["model"],
				$row["capacity"],
				$row["range"],
				$row["recharge"],
				$row["idle_discharge"]
			);
		}
		$this->log(sprintf("%d car models are loaded.", count($this->carModels)));
	}

	/**
	 * Returns car model specified by the code
	 *
	 * @param string $code Car model code
	 *
	 * @return Car Car model
	 *
	 * @throws Exception If invalid car model code.
	 */
	public function getCarModel($code) {
		if (isset($this->carModels[$code])) return $this->carModels[$code];
		throw new \Exception("Invalid car model code {$code}");
	}

	/**
	 * Loads travel start times
	 *
	 * Data columns:
	 * - string from Start location
	 * - string to Destination location
	 * - float start Start time of the day (0-24)
	 * - float end End time of the day (0-24)
	 * - float percentage Percentage (0-100)
	 *
	 * Travel start times are loaded from start_times.csv.
	 */
	protected function loadStartTimes() {
		$this->log("Loading start times...");
		$rows = $this->loadData(
			"start_times.csv",
			[
				"from" => ["string", FALSE],
				"to" => ["string", FALSE],
				"start" => ["float", FALSE, 0, 24],
				"end" => ["float", FALSE, 0, 24],
				"percentage" => ["float", FALSE, 0, 100],
			]
		);
		foreach ($rows as $row) {
			$this->startTimes[$row["from"]][$row["to"]][] = [
				"start" => $row["start"],
				"end" => $row["end"],
				"percentage" => $row["percentage"],
			];
		}
		$this->log("Starting times are loaded.");
	}

	/**
	 * Returns probabilistic travel start time for a route between two locations
	 *
	 * Start time distribution for the route should be available in start_times.csv
	 *
	 * @param string $from Start location
	 * @param string $to Destination location
	 *
	 * @return float Time of the day (h)
	 */
	public function getTravelStartTime($from, $to) {
		if (!isset($this->startTimes[$from])) {
			throw new \Exception("Invalid from location {$from}");
		}
		if (!isset($this->startTimes[$from][$to])) {
			throw new \Exception("Invalid to location {$to}");
		}
		$val = mt_rand() / mt_getrandmax() * 100;
		foreach ($this->startTimes[$from][$to] as $row) {
			if ($row["percentage"] < $val) continue;
			return $row["start"] + ($row["end"] - $row["start"]) * (mt_rand() / mt_getrandmax());
		}
		throw new \Exception("Invalid start time distribution {$from} - {$to}");
	}

	/**
	 * Returns travel duration between two locations for the specified time in a day
	 *
	 * @param string $from Start location
	 * @param string $to Destination location
   * @param float $time Time of the day (h) (optional)
	 *
	 * @return float Travel duration (h)
	 */
	public function getTravelDuration($from, $to, $time = NULL) {
		$dist = $this->getDistance($from, $to);
		return $dist / $this->getOption("defaultSpeed");
	}

	/**
	 * Creates agents
	 *
	 * Agent information is loaded from agents.csv as a matrix.
	 * Agent matrix includes the number of agents traveling between two locations.
	 *
	 * @throws Exception If invalid initial charge percentage.
	 * @throws Exception If invalid initial charge assignment method.
	 */
	protected function createAgents() {
		$this->log("Creating agents...");
		$initialChargeMethod = $this->getOption("initialChargeMethod");
		switch ($initialChargeMethod) {
		case "fixed":
			$initialCharge = $this->getOption("initialCharge");
			if ($initialCharge < 0 || $initialCharge > 100) {
				throw new \Exception("Invalid initial charge percentage {$initialCharge}");
			}
			break;
		case "random":
			$minInitialCharge = $this->getOption("minInitialCharge");
			if ($minInitialCharge < 0 || $minInitialCharge >= 100) {
				throw new \Exception("Invalid minimum initial charge percentage {$minInitialCharge}");
			}
			break;
		default:
			throw new \Exception("Invalid initial charge assignment method {$initialChargeMethod}");
		}
		// Load agent matrix
		$matrix = $this->loadMatrixData("agents.csv");
		$id = 1;
		// For each home location
		foreach ($matrix["rows"] as $home => $row) {
			// For each work location
			foreach ($matrix["cols"] as $work => $col) {
				// Get number of agents for the home-work location pair
				$n = $matrix["data"][$row][$col];
				// Create agents
				for ($i = 0; $i < $n; $i++) {
					$Car = clone $this->getCarModel($this->getOption("defaultCar"));
					switch ($initialChargeMethod) {
					case "fixed":
						$charge = $initialCharge;
						break;
					case "random":
						throw new \Exception("Not implemented");
						break;
					default:
						throw new \Exception("Invalid initial charge assignment method {$initialChargeMethod}");
					}
					$Car->setCharge($charge);
					$Agent = new Agent($this, $home, $work, $Car);
					$this->agents[$id++] = $Agent;
				}
			}
		}
		$this->log(sprintf("%d agents are loaded.", count($this->agents)));
	}

	/**
	 * Converts duration from unit to destination unit
	 *
	 * @param float $duration Duration
	 * @param string $unit Unit
	 * @param string $destUnit Destination unit
	 *
	 * @return float Duration in destination unit
	 *
	 * @throws Exception If invalid unit.
	 * @throws Exception If invalid destination unit.
	 */
	public static function convertDuration($duration, $unit, $destUnit) {
		switch ($unit) {
		case "h":
			$duration *= 3600;
			break;
		case "m":
			$duration *= 60;
			break;
		case "s":
			// NOP
			break;
		default:
			throw new \Exception("Invalid unit {$unit}");
		}
		switch ($destUnit) {
		case "h":
			$duration /= 3600;
			break;
		case "m":
			$duration /= 60;
			break;
		case "s":
			// NOP
			break;
		default:
			throw new \Exception("Invalid destination unit {$destUnit}");
		}
		return $duration;
	}

	/**
	 * Returns time of day for the specified timestamp
	 *
	 * @param float $timestamp Timestamp
	 *
	 * @return float Time of day (h)
	 */
	public static function getTimeOfDay(float $timestamp) {
		static $cache = [];
		if (empty($cache) || $cache["timestamp"] != $timestamp) {
			$timeofday = date("H", $timestamp) + date("i", $timestamp) / 60;
			$cache = ["timestamp" => $timestamp, "timeofday" => $timeofday];
		}
		return $cache["timeofday"];
	}

	/**
	 * Performs a model run
	 *
	 * Options:
	 * - int steps Number of steps to run
	 * - mixed duration Duration to run
	 *
	 * @param array $opts Options (optional)
	 *
	 * @return array Model results
	 */
	public function run(array $opts = []) {
		// Set options
		if (isset($opts["duration"])) {
			if (is_array($opts["duration"])) {
				$duration = self::convertDuration($opts["duration"][0], $opts["duration"][1], "s");
			}
			else {
				$duration = $this->opts["duration"];
			}
			if (!is_numeric($duration) || $duration <= 0) {
				throw new \Exception("Invalid duration {$duration}");
			}
			$this->log(sprintf("Running for %d s.", $duration));
		}
		else {
			$duration = NULL;
		}
		if (isset($opts["steps"])) {
			$steps = $opts["steps"];
			if (!is_int($steps) || $steps < 0) {
				throw new \Exception("Invalid steps {$steps}");
			}
			$this->log(sprintf("Running for %d steps.", $steps));
		}
		else {
			$steps = NULL;
		}

		$timespan = $this->getOption("timespan");
		$dateFormat = $this->getOption("dateFormat");
		$start = $this->timestamp;

		$this->log(sprintf("Run started at %s.", date($dateFormat, $start)));
		// Model loop
		for ($step = 1; empty($steps) || $steps >= $step; $step++) {
			// Calculate elapsed time
			$elapsed = $this->timestamp - $start;
			// Stop if target duration is reached
			if (isset($duration) && $elapsed >= $duration) {
				break;
			}
			// Log step
			$this->log(sprintf("Step %d, %s", $step, date($dateFormat, $this->timestamp + $timespan)));
			// Run agents
			foreach ($this->agents as $id => $Agent) {
				$Agent->run($timespan);
			}
			// Update timestamp
			$this->timestamp += $timespan;
		}
		$this->log(sprintf("Run finished at %s.", date($this->getOption("dateFormat"), $this->timestamp)));
	}

	public function getAgent($id) {
		if (isset($this->agents[$id])) return $this->agents[$id];
		throw new \Exception("Invalid agent id {$id}.");
	}

	/**
	 * Constructs a model
	 *
	 * @param array $opts Options (optional)
	 *
	 * @throws Exception If invalid date.
	 * @throws Exception If invalid time span.
	 * @throws Exception If invalid log agents.
	 */
	public function __construct(array $opts = []) {
		// Set options
		$this->opts = $opts + self::DEFAULT_OPTIONS;
		if ($this->opts["dataPath"]) {
			$this->opts["dataPath"] = rtrim($this->opts["dataPath"], "/");
		}
		if ($this->opts["date"]) {
			$this->timestamp = strtotime($this->opts["date"]);
			if ($this->timestamp === FALSE) {
				throw new \Exception("Invalid date {$this->opts['date']}");
			}
		}
		else {
			$this->timestamp = time();
		}
		if (is_array($this->opts["timespan"])) {
			$this->opts["timespan"] = self::convertDuration($this->opts["timespan"][0], $this->opts["timespan"][1], "s");
		}

		// Validate options
		if (empty($this->opts["timespan"]) || $this->opts["timespan"] < 0) {
			throw new \Exception("Invalid time span {$this->opts['timespan']}");
		}

		// Seed the random generator if required
		$seed = $this->getOption("randomSeed");
		if (isset($seed)) {
			$this->log("Seeding the random number generator with {$seed}.");
			mt_srand($seed);
		}

		// Load data
		$this->loadDistances();
		$this->loadRechargeBehaviors();
		$this->loadCarModels();
		$this->loadStartTimes();

		// Set default options based on loaded data
		if (empty($this->getOption("defaultCar"))) {
			$this->opts["defaultCar"] = array_key_first($this->carModels);
		}
		if (empty($this->getOption("defaultRechargeBehavior"))) {
			$this->opts["defaultRechargeBehavior"] = array_key_first($this->rechargeBehaviors);
		}

		// Create agents
		$this->createAgents();
	}
}
?>