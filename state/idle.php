<?php
namespace EVDemand\State;

require_once("state.php");

class Idle extends \EVDemand\State {
	/**
	 * Performs run operation for the specified duration
	 *
	 * @param float $duration Duration (s)
	 *
	 * @return float Elapsed time (s)	 
	 */
	protected function onRun(float $duration) {
		$Car = $this->getAgent()->getCar();

		// Get current charge percentage
		$charge = $Car->getCharge();

		// Check if not zero charge
		if ($charge != 0) {
			// Update charge percentage
			$charge -= $Car->getIdleDischargeRate() * \EVDemand\Model::convertDuration($duration, "s", "h");
			if ($charge < 0) {
				$charge = 0;
			}
			$Car->setCharge($charge);
		}
		
		// Return elapsed time
		return $duration;
	}
}
?>