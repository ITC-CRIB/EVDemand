<?php
namespace EVDemand\State;

require_once("state.php");

class Drive extends \EVDemand\State {
	// Start location
	private string $from;

	// End location
	private string $to;

	// Average speed (km/h)
	private float $speed;

	// Distance (km)
	private float $distance;

	// Distance to the end location (km)
	private float $distanceTo;

	/**
	 * Returns from location
	 *
	 * @return string From location
   */
	public function getFrom() {
		return $this->from;
	}

	/**
	 * Returns to location
	 *
	 * @return string To location
	 */
	public function getTo() {
		return $this->to;
	}

	/**
	 * Returns average speed (km/h)
	 *
	 * @return float Average speed (km/h)
	 */
	public function getSpeed() {
		return $this->speed;
	}

	/**
	 * Returns total distance to be traveled (km)
	 *
	 * @return float Total distance (km)
	 */
	public function getDistance() {
		return $this->distance;
	}

	/**
	 * Returns distance traveled from the start location (km)
	 *
	 * @return float Distance from the start location (km)
	 */
	public function getFromDistance() {
		return $this->distance - $this->distanceTo;
	}

	/**
	 * Returns distance to be traveled to the end location (km)
	 *
	 * @return float Distance to the end location (km)
	 */
	public function getToDistance() {
		return $this->distanceTo;
	}

	/**
	 * Calculates average speed (km)
	 *
	 * @return float Average speed (km/h)
	 */
	private function calculateSpeed() {
		// TODO: Customize speed based on agent, time and route
		return $this->getAgent()->getModel()->getOption("defaultSpeed");
	}

	/**
	 * Calculates current location
	 *
	 * @return string Current location
	 */
	private function calculateLocation() {
		if ($this->distanceTo == $this->distance) {
			return $this->from;
		}
		else if ($this->distanceTo == 0) {
			return $this->to;
		}
		else {
			return sprintf("%s|%s=%03d", $this->from, $this->to, round($this->distanceTo / $this->distance * 100));
		}
	}

	/**
	 * Handler for state start
	 */
	protected function onStart() {
		$this->distanceTo = $this->distance;
		$this->getAgent()->setLocation($this->calculateLocation(), $this->getToken());
	}

	/**
	 * Handler for state run for the specified duration
	 *
	 * @param float $duration Duration (s)
	 *
	 * @return float Elapsed time (s)	 
	 */
	protected function onRun(float $duration) {
		$Car = $this->getAgent()->getCar();
		
		// Get current charge percentage
		$charge = $Car->getCharge();
		
		// Return if zero charge
		if ($charge == 0) return $duration;
		
		// Calculate distance that can be traveled
		$speed = $this->getSpeed() / 3600;
		$distance = $speed * $duration;

		// Correct distance if it is more than the maximum distance that can be traveled
		$distanceMax = ($charge / 100) * $Car->getFullRange();
		if ($distance > $distanceMax) {
			$distance = $distanceMax;
		}

		// Correct distance if it is more than the distance to the end location
		if ($distance > $this->distanceTo) {
			$distance = $this->distanceTo;
		}

		// Update charge percentage
		$charge -= $distance / $Car->getFullRange() * 100;
		$Car->setCharge($charge);		

		// Update distance to end location
		$this->distanceTo -= $distance;
		$this->getAgent()->setLocation($this->calculateLocation(), $this->getToken());
		
		// Stop driving if destination is reached
		if ($this->distanceTo == 0) {
			$this->stop();
		}

		// Return elapsed time
		return $distance / $speed;
	}

	/**
	 * Returns drive state log information
	 *
	 * @return array Drive state log information
	 */
	public function getLog() {
		return parent::getLog() + [
			"from" => $this->from,
			"to" => $this->to,
			"speed" => $this->speed,
		];
	}

	/**
	 * Constructs a drive state
	 *
	 * @param \EVDemand\Agent $Agent Agent of the state
	 * @param string $from Start location
	 * @param string $to End location
	 */
	public function __construct(\EVDemand\Agent $Agent, $token, $from, $to) {
		parent::__construct($Agent, $token);
		$this->from = $from;
		$this->to = $to;
		$this->distance = $Agent->getModel()->getDistance($Agent->getLocationId($from), $Agent->getLocationId($to));
		$this->speed = $this->calculateSpeed();
	}
}
?>