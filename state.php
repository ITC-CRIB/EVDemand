<?php
namespace EVDemand;

require_once("model.php");

abstract class State {
	// Statuses
	const PENDING = 0;
	const RUNNING = 1;
	const STOPPED = 2;
	
	// Agent
	private Agent $Agent;

	// Access token
	private int $token;

	// Status
	private int $status;
	
	// Duration of the state (s)
	private float $duration;

	// Elapsed time (s)
	private float $elapsed;

	/**
	 * Returns agent of the state
	 *
	 * @return Agent Agent of the state
	 */
	public function getAgent() {
		return $this->Agent;
	}
	
	/**
	 * Returns access token of the agent
	 *
	 * @return int Access token of the agent
	 */
	protected function getToken() {
		return $this->token;
	}

	/**
	 * Returns status of the state
	 *
	 * @return int Status of the state
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * Returns duration of the state in the specified unit
	 *
	 * @param string $unit Duration unit (default = "s")
	 *
	 * @return float Duration in the specified unit if set, NULL otherwise
	 *
	 * @throws Exception If invalid duration unit.
	 */
	public function getDuration($unit = "s") {
		if (!isset($this->duration)) return NULL;
		return $unit == "s" ? $this->duration : Model::convertDuration($this->duration, "s", $unit);
	}

	/**
	 * Sets duration of the state
	 *
	 * @param mixed $duration Duration
	 * @param string $unit Duration unit (default = "s")
	 *
	 * @throws Exception If invalid duration.
	 * @throws Exception If duration is shorter than the elapsed time.
	 */
	public function setDuration(float $duration, $unit = "s") {
		if ($duration < 0) {
			throw new \Exception("Invalid duration {$duration}.");
		}
		if ($unit != "s") {
			$duration = Model::convertDuration($duration, $unit, "s");
		}
		if ($this->elapsed > $duration) {
			throw new \Exception("Duration is shorter than the elapsed time.");
		}
		$this->duration = $duration;
	}

	/**
	 * Returns elapsed time (s)
	 *
	 * @return float Elapsed time (s)
	 */
	public function getElapsedTime() {
		return $this->elapsed;
	}

	/**
	 * Handler for state start
	 */
	protected function onStart() {
		// NOP
	}

	/**
	 * Starts the state
	 *
	 * @throws Exception If state is already started.
	 */
	public function start() {
		if ($this->status != self::PENDING) {
			throw new \Exception("State is already started.");
		}
		$this->onStart();
		$this->status = self::RUNNING;
		$this->elapsed = 0;
	}

	/**
	 * Handler for state stop
	 */
	protected function onStop() {
		// NOP
	}

	/**
	 * Stops the state
	 *
	 * @throws Exception If state is not running.
	 */
	public function stop() {
		if ($this->status != self::RUNNING) {
			throw new \Exception("State is not running.");
		}
		$this->onStop();
		$this->status = self::STOPPED;
	}

	/**
	 * Handler for state run for the specified duration
	 *
	 * @param float $duration Duration (s)
	 *
	 * @return float Elapsed duration (s)
	 */
	abstract protected function onRun(float $duration);

	/**
	* Runs state for the specified duration
	*
	* @param mixed $duration Duration to run the state
	* @param string $unit Duration unit (default = "s")
	*
	* @return float Elapsed time (s)
	*
	* @throws Exception If state is stopped.
	* @throws Exception If invalid duration.
	*/
	public function run(float $duration, $unit = "s") {
		// Throw exception if state is stopped
		if ($this->status == self::STOPPED) {
			throw new \Exception("State is stopped.");
		}
		// Start state if not running
		if ($this->status != self::RUNNING) {
			$this->start();
		}
		// Return if zero duration
		if (empty($duration)) return 0;
		// Throw exception if invalid duration
		if ($duration < 0) {
			throw new \Exception("Invalid duration {$duration}");
		}
		// Convert duration into seconds if required
		if ($unit != "s") {
			$duration = Model::convertDuration($duration, $unit, "s");
		}
			// Check if remaining time is less than the run duration
		if (isset($this->duration) && $duration >= $this->duration - $this->elapsed) {
			// Call run handler with remaining time
			$elapsed = $this->onRun($this->duration - $this->elapsed);
			// Stop state if required
			if ($this->status != self::STOPPED) {
				$this->stop();
			}
		}
		else {
			// Call run handler
			$elapsed = $this->onRun($duration);
		}
		// Updated elapsed time
		$this->elapsed += $elapsed;
		// Return elapsed time
		return $elapsed;
	}

	/**
	 * Returns state log information
	 *
	 * @return array State log information
	 */
	public function getLog() {
		$log = ["status" => $this->status];
		if (isset($this->elapsed)) $log["elapsed"] = $this->elapsed;
		if (isset($this->duration)) $log["duration"] = $this->duration;
		return $log;
	}

	/**
	 * Constructs a state
	 *
	 * @param Agent $Agent Agent of the state
	 * @param int $token Access token of the agent (optional)
	 */
	public function __construct(Agent $Agent, int $token = NULL) {
		$this->Agent = $Agent;
		$this->status = self::PENDING;
		if (isset($token)) $this->token = $token;
	}
}
?>