<?php
namespace EVDemand;

class Car {
	// Brand
	private string $brand;

	// Model
	private string $model;

	// Charge capacity (kWh)
	private float $capacity;

	// Recharge time from zero to full (h)
	private float $fullRecharge;

	// Maximum range when full charge (km)
	private float $fullRange;

	// Idle discharge rate (%/h)
	private float $idleDischarge;

	// Charge percentage (%)
	private float $charge;

	/**
	 * Returns brand of the car
	 *
	 * @return string Brand of the car
	 */
	public function getBrand() {
		return $this->brand;
	}

	/**
	 * Returns model of the car
	 *
	 * @return string Model of the car
	 */
	public function getModel() {
		return $this->model;
	}

	/**
	 * Returns charge capacity (kWh)
	 *
	 * @return float Charge capacity
	 */
	public function getChargeCapacity() {
		return $this->capacity;
	}

	/**
	 * Returns maximum range when at full charge (km)
	 *
	 * @return float Maximum range when at full charge (km)
	 */
	public function getFullRange() {
		return $this->fullRange;
	}

	/**
	 * Returns recharge time from zero to full (h)
	 *
	 * @return float Recharge time (h)
	 */
	public function getFullRechargeTime() {
		return $this->fullRecharge;
	}

	/**
	 * Returns idle discharge rate (%/h)
	 *
	 * @return float Idle discharge rate (%/h)
	 */
	public function getIdleDischargeRate() {
		return $this->idleDischarge;
	}

	/**
	 * Returns current charge percentage (%)
	 *
	 * @return float Charge percentage
	 */
	public function getCharge() {
		return $this->charge;
	}

	/**
	 * Sets current charge percentage (%)
	 *
	 * @param float $charge Charge percentage (0-100)
	 */
	public function setCharge(float $charge) {
		if ($charge < 0 || $charge > 100) {
			throw new \Exception("Invalid charge percentage {$charge}.");
		}
		$this->charge = $charge;
	}

	/**
	 * Sets current charge by a delta percentage (%)
	 *
	 * @param float $delta Delta charge percentage (%)
	 *
	 * @throws Exception If invalid charge delta percentage
	 */
	public function setChargeByDelta(float $delta) {
		$charge = $this->charge + $delta;
		try {
			$this->setCharge($charge);
		}
		catch (\Exception $Exception) {
			throw new \Exception("Invalid charge delta percentage {$delta}.");
		}
	}

	/**
	 * Constructs a car object
	 *
	 * @param string $brand Brand of the car
	 * @param string $model Model of the car
	 * @param float $capacity Charging capacity of the car (kWh)
	 * @param float $fullRange Maximum range of the car when at full charge (km)
	 * @param float $fullRecharge Recharge time from zero to full (h)
	 * @param float $idleDischarge Idle discharge rate (%/h) (default = 0)
	 */
	public function __construct(string $brand, string $model, float $capacity, float $fullRange, float $fullRecharge, float $idleDischarge = 0) {
		$this->brand = $brand;
		$this->model = $model;
		if ($capacity < 0) {
			throw new \Exception("Invalid capacity {$capacity}.");
		}
		$this->capacity = $capacity;
		if ($fullRange < 0) {
			throw new \Exception("Invalid full range {$fullRange}.");
		}
		$this->fullRange = $fullRange;
		if ($fullRecharge < 0) {
			throw new \Exception("Invalid full recharge time {$fullRecharge}.");
		}
		$this->fullRecharge = $fullRecharge;
		if ($idleDischarge < 0) {
			throw new \Exception("Invalid idle discharge rate {$idleDischarge}.");
		}
		$this->idleDischarge = $idleDischarge;
		$this->charge = 100;
	}
}
?>