<?php

namespace MiniBosses;

use pocketmine\scheduler\Task;
use pocketmine\level\Level;

class EventCheckTask extends Task {
	public $particle, $level, $event, $owner;
	public function __construct(Boss $owner, $particle, Level $level, $event) {

        $this->particle = $particle;
		$this->level = $level;
		$this->event = $event;
		$this->owner = $owner;
	}

	public function onRun($currentTick) {
        /** @var $owner DamageEffect */
        $owner = $this->owner;
		$owner->eventCheck ( $this->particle, $this->level , $this->event);
	}
}

?>