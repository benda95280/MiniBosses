<?php

namespace MiniBosses;

use pocketmine\scheduler\Task;
use pocketmine\level\Level;

class DeleteParticlesTask extends Task {
	public $particle, $level, $owner;

	public function __construct(Boss $owner, $particle, Level $level) {

		$this->particle = $particle;
		$this->level = $level;
		$this->owner = $owner;

	}

	public function onRun($currentTick) {
        /** @var $owner DamageEffect */
        $owner = $this->owner;
        $owner->deleteParticles ( $this->particle, $this->level );
	}
}

?>