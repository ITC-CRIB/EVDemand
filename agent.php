<?php
namespace EVDemand;

require_once("model.php");
require_once("state.php");
require_once("state/idle.php");
require_once("state/drive.php");
require_once("state/recharge.php");
require_once("car.php");

class Agent {
	// Model
	private Model $Model;

	// Timestamp
	private float $timestamp;

	// Access token
	private $token;

	// Home location of the agent
	private $home;

	// Work location of the agent
	private $work;

	// Time to leave for home from work
	private $timeToWork;

	// Time to leave for work from home
	private $timeToHome;

	// Location of the agent
	private $location;

	// Car of the agent
	private Car $Car;

	// Recharge behavior of the agent
	private $rechargeBehavior;

	// State of the agent
	private State $State;

	// State logs
	private array $logs = [];

	// State history
	private array $states = [];

	/**
	 * Returns model of the agent
	 *
	 * @return Model Model of the agent
	 */
	public function getModel() {
		return $this->Model;
	}

	/**
	 * Returns timestamp of the agent
	 *
	 * @return float Timestamp of the agent
	 */
	public function getTimestamp() {
		return $this->timestamp;
	}

	/**
	 * Returns home location of the agent
	 *
	 * @return string Home location
	 */
	public function getHome() {
		return $this->home;
	}

	/**
	 * Returns work location of the agent
	 *
	 * @return string Work location
	 */
	public function getWork() {
		return $this->work;
	}

	/**
	 * Returns time to leave home to go to work
	 *
	 * @return float Time to leave home to go to work
	 */
	public function getTimeToWork() {
		return $this->timeToWork;
	}

	/**
	 * Returns time to leave work to go to home
	 *
	 * @return float Time to leave work to go to home
	 */
	public function getTimeToHome() {
		return $this->timeToHome;
	}

	/**
	 * Returns current location of the agent
	 *
	 * @return string Location of the agent
	 */
	public function getLocation() {
		return $this->location;
	}

	public function getLocationId() {
		if ($this->location == "home") return $this->home;
		if ($this->location == "work") return $this->work;
		return $this->location;
	}

	/**
	 * Sets current location of the agent
	 *
	 * @param string $location Location
	 * @param string $token Access token
	 *
	 * @throws Exception If invalid access token.
	 */
	public function setLocation($location, $token) {
		if ($token != $this->token) {
			throw new \Exception("Invalid access token {$token}");
		}
		$this->location = $location;
	}

	/**
	 * Returns car of the agent
	 *
	 * @return Car Car of the agent
	 */
	public function getCar() {
		return $this->Car;
	}

	/**
	 * Returns state of the agent
	 *
	 * @return State State of the agent
	 */
	public function getState() {
		return $this->State;
	}

	/**
	 * Returns Logs of the agent
	 *
	 * @return array Logs of the agent
	 */
	public function getLogs() {
		return $this->logs;
	}

	/**
	 * Returns log of the agent
	 *
	 * @return array Log of the agent
	 */
	public function getLog() {
		return (
			[
				"state" => get_class($this->State),
				"location" => $this->location,
				"charge" => $this->Car->getCharge(),
			]
			+
			$this->State->getLog()
		);
	}

	/**
	 * Logs the agent
	 */
	protected function log() {
		// Append log to the log history
		$this->logs[] = ["timestamp" => $this->timestamp] + $this->getLog();

		// Prune log history if required
		$max = $this->Model->getOption("maxAgentLogs");
		if ($max) while (count($this->logs) > $max) {
			$log = array_shift($this->logs);
			unset($log);
		}
	}

	/**
	 * Checks if the agent wants to recharge the car
	 *
	 * @return bool TRUE if the agent want to recharge, FALSE otherwise
	 */
	protected function wantRecharge() {
		$desire = $this->Model->getRechargeDesire($this->rechargeBehavior, $this->Car->getCharge());
		echo $desire . "\n";
	}

	/**
	 * Checks if the agent can recharge the car
	 *
	 * @return bool TRUE if the agent can recharge, FALSE otherwise
	 */
	protected function canRecharge() {
		if ($this->location == "home") {
			return TRUE;
		}
		if ($this->location == "work") {
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * Returns the next state based on the state history
	 *
	 * @return State Next state
	 *
	 * @throws Exception If invalid last state.
	 */
	public function getNextState() {
		// Get last state
		$lastState = $this->State;

		if ($lastState instanceof State\Drive) {
			if ($this->wantRecharge()) {
				if ($this->canRecharge()) {
					$State = new State\Recharge($this);
				}
				else {
					$State = new State\Idle($this);
				}
			}
			else {
				$State = new State\Idle($this);
			}			
		}
		else if ($lastState instanceof State\Idle || $lastState instanceof State\Recharge) {
			if ($this->location == "home") {
				$State = new State\Drive($this, $this->token, "home", "work");
			}
			else {
				$State = new State\Drive($this, $this->token, "work", "home");
			}			
		}
		else {
			throw new \Exception("Invalid last state {$lastState}.");
		}

		// Return state
		return $State;
	}

	/**
	 * Sets initial state of the agent
	 *
	 * Time stamp of the model is used to select the initial state.
	 */
	protected function setInitialState() {
		// Set timestamp
		$this->timestamp = $this->Model->getTimestamp();
		// Get time of the day
		$time = Model::getTimeOfDay($this->timestamp);
		// Check if time is less than the time to leave home for work
		if ($time < $this->timeToWork) {
			$this->location = "home";
			$this->State = new State\Idle($this);
		}
		else {
			$duration = $this->Model->getTravelDuration($this->home, $this->work, $this->timeToWork);
			// Check if the time is during the travel from home to work
			if ($time < $this->timeToWork + $duration) {
				$this->State = new State\Drive($this, $this->token, "home", "work");
				$this->State->run($time - $this->timeToWork, "h");
			}
			// Check if the time is during working hours
			else if ($time < $this->timeToHome) {
				$this->location = "work";
				$this->State = new State\Idle($this);
				$this->State->run($time - $this->timeToWork - $duration, "h");
			}
			else {
				$duration = $this->Model->getTravelDuration($this->work, $this->home, $this->timeToHome);
				// Check if the time is during the travel from work to home
				if ($time < $this->timeToHome + $duration) {
					$this->State = new State\Drive($this, $this->token, "work", "home");
					$this->State->run($time - $this->timeToHome, "h");
				}
				// Time is during home hours
				else {
					$this->location = "home";
					$this->State = new State\Idle($this);
					$this->State->run($time - $this->timeToHome - $duration, "h");
				}
			}
		}
		$this->log();
	}

	protected function checkState() {
		$time = Model::getTimeOfDay($this->timestamp);
		if ($time < $this->timeToWork || $time > $this->timeToHome) {
			if ($this->location == "home") {
				// NOP
			}
			else if ($this->State instanceof State\Drive && $this->State->getTo() == "home") {
				// NOP
			}
			else {
				$State = new State\Drive($this, $this->token, $this->location, "home");
			}
		}
		else {
			if ($this->location == "work") {
				// NOP
			}
			else if ($this->State instanceof State\Drive && $this->State->getTo() == "work") {
				// NOP
			}
			else {
				$State = new State\Drive($this, $this->token, $this->location, "work");
			}
		}
		if (empty($State)) return;
		$this->State->stop();
		$this->changeState($State);
	}

	protected function changeState(State $State) {
		// Log current state
		$this->log();

		// Append current state to the state history
		$this->states[] = $this->State;

		// Prune state history if required
		$max = $this->Model->getOption("maxAgentStates");
		if ($max) while (count($this->states) > $max) {
			$state = array_shift($this->states);
			unset($state);
		}

		// Set state
		$this->State = $State;
		
		// Start state
		$this->State->start();
	}

	/**
	 * Runs the agent for the specified duration
	 *
	 * @param mixed $duration Duration
	 * @param string $unit Duration unit (default = "s")
	 */
	public function run(float $duration, $unit = "s") {
		// Return if zero duration
		if (empty($duration)) return;
		// Throw exception if invalid duration
		if ($duration < 0) {
			throw new \Exception("Invalid duration {$duration}.");
		}
		// Get duration in seconds if required
		if ($unit != "s") {
			$duration = Model::convertDuration($duration, $unit, "s");
		}
		// Run state
		$elapsed = $this->State->run($duration);
		// Update timestamp
		$this->timestamp += $elapsed;
		// Return if state if not stopped
		if ($this->State->getStatus() == State::STOPPED) {
			// Get next state
			$State = $this->getNextState();
			// Change state
			$this->changeState($State);
			// Run state
			$this->run($duration - $elapsed);			
		}
		$this->checkState();
	}

	/**
	 * Constructs an agent
	 *
	 * @param Model $Model Model of the agent
	 * @param string $home Home location of the agent
	 * @param string $work Work location of the agent
	 * @param mixed $car Car of the agent
	 */
	public function __construct(Model $Model, $home, $work, $car = NULL, $rechargeBehavior = NULL) {
		$this->Model = $Model;
		$this->token = mt_rand();
		$this->home = $home;
		$this->work = $work;
		if (empty($car)) {
			$car = $Model->getOption("defaultCar");
		}
		$this->Car = $car instanceof Car ? $car : clone $Model->getCarModel($car);
		if (empty($rechargeBehavior)) {
			$rechargeBehavior = $Model->getOption("defaultRechargeBehavior");
		}
		$this->rechargeBehavior = $rechargeBehavior;
		$this->timeToWork = $Model->getTravelStartTime("home", "work");
		$this->timeToHome = $Model->getTravelStartTime("work", "home");
		$this->setInitialState();
	}
}
?>