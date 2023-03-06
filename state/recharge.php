<?php
namespace EVDemand\State;

require_once("state.php");

class Recharge extends \EVDemand\State {
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

		// Check if not full charge
		if ($charge != 100) {
			// Update charge percentage
			$charge += \EVDemand\Model::convertDuration($duration, "s", "h") / $Car->getFullRechargeTime() * 100;
			if ($charge > 100) {
				$charge = 100;
			}
			$Car->setCharge($charge);
		}
		
		// Return elapsed time
		return $duration;
	}
}
?>