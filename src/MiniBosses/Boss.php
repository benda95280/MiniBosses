<?php

namespace MiniBosses;

use pocketmine\entity\Creature;
use pocketmine\entity\EntityIds;
use pocketmine\entity\Living;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\level\particle\DestroyBlockParticle;
use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\Player;
use pocketmine\utils\UUID;
use pocketmine\utils\TextFormat;

class Boss extends Creature{

	const NETWORK_ID = 1000;
	/** @var Main */
	private $plugin;
	/** @var Living */
	public $target;
	/** @var Position  */
	public $spawnPos;
	/** @var float  */
	public $attackDamage,$speed,$range,$scale;
	/** @var int */
	public $networkId,$attackRate, $attackDelay = 0,$respawnTime,$knockbackTicks = 0;
	/** @var Item[][]|int[][] */
	public $drops = array();
	/** @var Skin */
	public $skin;
	/** @var Item  */
	public $heldItem;
	public $autoAttack;
	


	public function __construct(Level $level,CompoundTag $nbt){
		$this->scale = $nbt->getFloat("scale",1);
		$this->height = $this->width = $this->scale;
		$this->networkId = (int)$nbt->getInt("networkId");
		$this->range = $nbt->getFloat("range",10);
		$spawnPos = $nbt->getListTag("spawnPos")->getAllValues();
		$this->spawnPos = new Position($spawnPos[0],$spawnPos[1],$spawnPos[2],$this->level);
		$this->attackDamage = $nbt->getFloat("attackDamage",1);
		$this->attackRate = $nbt->getInt("attackRate",10);
		$this->speed = $nbt->getFloat("speed",1);
		$drops = $nbt->getString("drops","");
		if($drops !== ""){
			foreach(explode(' ',$drops) as $item){
				$item = explode(';',$item);
				$this->drops[] = [Item::get($item[0],$item[1] ?? 0,$item[2] ?? 1,$item[3] ?? ""),$item[4] ?? 100];
			}
		}
		$this->respawnTime = $nbt->getInt("respawnTime",100);
		$heldItem = $nbt->getString("heldItem","");
		if($heldItem !== ""){
			$heldItem = explode(';',$heldItem);
			$this->heldItem = Item::get($heldItem[0],$heldItem[1] ?? 0,$heldItem[2] ?? 1,$heldItem[3] ?? "");
		}else{
			$this->heldItem = Item::get(Item::AIR);
		}
		if($this->networkId === EntityIds::PLAYER){
			$this->skin = self::deserializeSkinNBT($nbt);
			$this->baseOffset = 1.62;
		}
		$this->autoAttack = $nbt->getByte("autoAttack",false);
		parent::__construct($level,$nbt);
	}

	/**
	 * @param CompoundTag $nbt
	 *
	 * @return Skin
	 * @throws \InvalidArgumentException
	 */
	private function deserializeSkinNBT(CompoundTag $nbt) : Skin{
		if($nbt->hasTag("skin",StringTag::class)){
			$skin = new Skin(mt_rand(-PHP_INT_MAX,PHP_INT_MAX)."_Custom",$nbt->getString("skin",""));
		}else{
			$skinTag = $nbt->getCompoundTag("Skin");
			$skin = new Skin(
				$skinTag->getString("Name"),
				$skinTag->hasTag("Data", StringTag::class) ? $skinTag->getString("Data") : $skinTag->getByteArray("Data"), //old data (this used to be saved as a StringTag in older versions of PM)
				$skinTag->getByteArray("CapeData", ""),
				$skinTag->getString("GeometryName", ""),
				$skinTag->getByteArray("GeometryData", "")
			);
		}
		$skin->validate();
		return $skin;
	}

	public function initEntity(): void{
		$this->plugin = $this->server->getPluginManager()->getPlugin("MiniBosses");
        parent::initEntity();
        $this->setImmobile();
        $this->setScale($this->namedtag->getFloat("scale"));
		if($this->namedtag->hasTag("maxHealth",IntTag::class)){
			$health = (int)$this->namedtag->getInt("maxHealth");
			parent::setMaxHealth($health);
			$this->setHealth($health);
		}else{
			$this->setMaxHealth(20);
			$this->setHealth(20);
		}
    }

	public function getName(): string{
		return $this->getNameTag();
	}

	public function sendSpawnPacket(Player $player): void{
		if($this->networkId === EntityIds::PLAYER){
			$uuid = UUID::fromData($this->getId(), $this->skin->getSkinData(), $this->getName());
			$pk = new PlayerListPacket();
			$pk->type = PlayerListPacket::TYPE_ADD;
			$pk->entries = [PlayerListEntry::createAdditionEntry($uuid, $this->id, $this->getName(), $this->skin)];
			$player->dataPacket($pk);
			$pk = new AddPlayerPacket();
			$pk->uuid = $uuid;
			$pk->username = $this->getName();
			$pk->entityRuntimeId = $this->getId();
			$pk->position = $this->asVector3();
			$pk->motion = $this->getMotion();
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->item = $this->heldItem;
			$pk->metadata = $this->getDataPropertyManager()->getAll();
			$player->dataPacket($pk);
			$this->sendData($player, [self::DATA_NAMETAG => [self::DATA_TYPE_STRING, $this->getName()]]);//Hack for MCPE 1.2.13: DATA_NAMETAG is useless in AddPlayerPacket, so it has to be sent separately
			$pk = new PlayerListPacket();
			$pk->type = PlayerListPacket::TYPE_REMOVE;
			$pk->entries = [PlayerListEntry::createRemovalEntry($uuid)];
			$player->dataPacket($pk);
		}else{
			$pk = new AddEntityPacket();
			$pk->entityRuntimeId = $this->getID();
			$pk->type = $this->networkId;
			$pk->position = $this->asVector3();
			$pk->motion = $this->getMotion();
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->metadata = $this->getDataPropertyManager()->getAll();
			$player->dataPacket($pk);
			if(!$this->heldItem->isNull()){
				$pk = new MobEquipmentPacket();
				$pk->entityRuntimeId = $this->getId();
				$pk->item = $this->heldItem;
				$pk->inventorySlot = 0;
				$pk->hotbarSlot = 0;
				$player->dataPacket($pk);
			}
		}
	}

	public function setMaxHealth(int $health): void{
		$this->namedtag->setInt("maxHealth",$health);
		parent::setMaxHealth($health);
	}

	public function saveNBT():void {
        parent::saveNBT();
		$this->namedtag->setInt("maxHealth",$this->getMaxHealth());
		$this->namedtag->setTag(new ListTag("spawnPos", [
			new DoubleTag("", $this->spawnPos->x),
			new DoubleTag("", $this->spawnPos->y),
			new DoubleTag("", $this->spawnPos->z)
		]));
		$this->namedtag->setFloat("range",$this->range);
		$this->namedtag->setFloat("attackDamage",$this->attackDamage);
		$this->namedtag->setInt("networkId",$this->networkId);
		$this->namedtag->setInt("attackRate",$this->attackRate);
		$this->namedtag->setFloat("speed",$this->speed);
		$drops2 = [];
		foreach($this->drops as $drop)
			$drops2[] = $drop[0]->getId().";".$drop[0]->getDamage().";".$drop[0]->getCount().";".(new LittleEndianNBTStream())->write($drop[0]->getNamedTag()).";".$drop[1];
		$this->namedtag->setString("drops",implode(' ',$drops2));
		$this->namedtag->setInt("respawnTime",$this->respawnTime);
		if($this->skin !== null){
			$this->namedtag->setTag(new CompoundTag("Skin", [
				new StringTag("Name", $this->skin->getSkinId()),
				new ByteArrayTag("Data", $this->skin->getSkinData()),
				new ByteArrayTag("CapeData", $this->skin->getCapeData()),
				new StringTag("GeometryName", $this->skin->getGeometryName()),
				new ByteArrayTag("GeometryData", $this->skin->getGeometryData())
			]));
		}
		$this->namedtag->removeTag("skin");//old data
		$this->namedtag->setString("heldItem",($this->heldItem instanceof Item ? $this->heldItem->getId().";".$this->heldItem->getDamage().";".$this->heldItem->getCount().";".(new LittleEndianNBTStream())->write($this->heldItem->getNamedTag()) : ""));
		$this->namedtag->setFloat("scale", $this->scale);
		$this->namedtag->setByte("autoAttack",$this->autoAttack);
	}

	public function onUpdate(int $currentTick): bool {
		$tickDiff = $currentTick - $this->lastUpdate;
		if($this->knockbackTicks > 0) $this->knockbackTicks--;
		if($this->isAlive()){
			$player = $this->target;
			if(!$player instanceof Living && $this->autoAttack){
				$dist = $this->range;
				foreach($this->level->getPlayers() as $entity){
					if(($d = $entity->distanceSquared($this)) < $dist){
						$player = $entity;
						$dist = $d;
					}
				}
				$this->target = $player;
			}
			if($player instanceof Living && $player->isAlive() && !$player->isClosed()){
				if($this->distanceSquared($this->spawnPos) > $this->range){
					$this->setPosition($this->spawnPos);
					$this->setHealth($this->getMaxHealth());
					$this->target = null;
				} else{
					$dx = $this->motion->x * $tickDiff;
					$dz = $this->motion->z * $tickDiff;
					$isJump = false;
					if ($this->isCollidedHorizontally or $this->isUnderwater()) {
						$isJump = $this->checkJump($dx, $dz);
						echo "checkjump";	
					}
					if(!$isJump){
						if(!$this->onGround){
							if($this->motion->y > -$this->gravity * 4){
								$this->motion->y = -$this->gravity * 4;
							} else{
								$this->motion->y -= $this->gravity;
							}
							$this->move($this->motion->x, $this->motion->y, $this->motion->z);
						} 
						if($this->knockbackTicks > 0){
						}
					}
						
							$x = $player->x - $this->x;
							$y = $player->y - $this->y;
							$z = $player->z - $this->z;
							if($x ** 2 + $z ** 2 < 0.7){
								$this->motion->x = 0;
								$this->motion->z = 0;
							} else{
								$diff = abs($x) + abs($z);
								$this->motion->x = $this->speed * 0.15 * ($x / $diff);
								$this->motion->z = $this->speed * 0.15 * ($z / $diff);
							}
							$this->yaw = rad2deg(atan2(-$x, $z));
							if($this->networkId === EntityIds::ENDER_DRAGON){
								$this->yaw += 180;
							}
							$this->pitch = rad2deg(atan(-$y));
							$this->move($this->motion->x, $this->motion->y, $this->motion->z);
							if($this->distanceSquared($player) < $this->scale && $this->attackDelay++ > $this->attackRate){
								$this->attackDelay = 0;
								if (mt_rand(0,100) > 87) {
									$finalAttackDamage = $this->attackDamage * 2;
									$player->sendTip(TextFormat::RED . "** Critical HIT: ".$finalAttackDamage. " **");
								}
								else $finalAttackDamage = $this->attackDamage;							
								
								$ev = new EntityDamageByEntityEvent($this, $player, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $finalAttackDamage);
								$player->attack($ev);
								// Monster turn arround player
								if (mt_rand(0,100) > 90 && !$isJump) { 
									$blocks = 2;
									$angle = mt_rand(40,180);
									$x = $this->motion->x;
									$z = $this->motion->z;
									$yaw = $this->yaw - $angle;
									$deltaX = sin($yaw)*$blocks;
									$deltaZ = cos($yaw)*$blocks;
									$this->move(round($deltaX+$x), $this->motion->y, round($deltaZ+$z));
								}
							}
						
					
				}
			}else{
				$this->setPosition($this->spawnPos);
				$this->setHealth($this->getMaxHealth());
				$this->target = null;
			}
			$this->updateMovement();
		}
		parent::onUpdate($currentTick);
		return !$this->closed;
	}

	public function attack(EntityDamageEvent $source): void{
		if(!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent){
			$entity = $source->getEntity();
			$dmg = $source->getDamager();
			$damage = $source->getFinalDamage();
			if($dmg instanceof Player){
				parent::attack($source);
				// ###MISSED HIT###
				if (mt_rand(0,100) > 90) {
					echo "Missed";
					$pos = $source->getEntity()->add(0.1 * mt_rand(1, 9) * mt_rand(-1, 1), 0.1 * mt_rand(5, 9), 0.1 * mt_rand(1, 9) * mt_rand(-1, 1));
					$missedParticle = new FloatingTextParticle($pos, "", TextFormat::GRAY . "MISS");
					$this->plugin->getScheduler()->scheduleDelayedTask(new EventCheckTask($this, $missedParticle, $source->getEntity()->getLevel(), $source), 1);
					$dirVec = $entity->getDirectionVector();
					$entity->setMotion(new Vector3($dirVec->getX(), -0.3, $dirVec->getZ()));
					$source->setCancelled(true);
				}
				// ###HIT###
				if(!$source->isCancelled()){
					$this->sprayBlood($entity,$damage); //Need pass damage to function
					$this->target = $dmg;
					$this->motion->x = ($this->x - $dmg->x) * 0.19;
					$this->motion->y = 0.5;
					$this->motion->z = ($this->z - $dmg->z) * 0.19;
					$this->knockbackTicks = mt_rand(0,12);

					 if ($damage < 3) {
						 $color = TextFormat::GREEN;
					 } else {
						 if ($damage < 6) {
							 $color = TextFormat::YELLOW;
						 } else {
							 $color = TextFormat::RED;
						 }
					 }
					 $pos = $source->getEntity()->add(0.1 * mt_rand(1, 9) * mt_rand(-1, 1), 0.1 * mt_rand(5, 9), 0.1 * mt_rand(1, 9) * mt_rand(-1, 1));
					 $damageParticle = new FloatingTextParticle($pos, "", $color . "-" . $damage);
					 if ($source->getEntity()->getHealth() < 7) {
						 $color = TextFormat::RED;
					 } else {
						 if ($source->getEntity()->getHealth() < 14) {
							 $color = TextFormat::YELLOW;
						 } else {
							 $color = TextFormat::GREEN;
						 }
					 }
					 $pos = $source->getEntity()->add(0, 1.5, 0);
					 $healthParticle = new FloatingTextParticle($pos, "", $color . ($source->getEntity()->getHealth() - $damage) . " / " . $source->getEntity()->getMaxHealth());
					 $this->plugin->getScheduler()->scheduleDelayedTask(new EventCheckTask($this, $damageParticle, $source->getEntity()->getLevel(), $source), 1);
					 $this->plugin->getScheduler()->scheduleDelayedTask(new EventCheckTask($this, $healthParticle, $source->getEntity()->getLevel(), $source), 1);

				}
			}
        }
    }
	


	/**
	 * This method checks the jumping for the entity. It should only be called when isCollidedHorizontally is set to
	 * true on the entity.
	 *
	 * @param int $dx
	 * @param int $dz
	 *
	 * @return bool
	 */
	protected function checkJump($dx, $dz){
		echo "$this: entering checkJump [dx:$dx] [dz:$dz]";
					echo"Testjump";
		if($this->motion->y == $this->gravity * 2){ // swimming
			// PureEntities::logOutput("$this: checkJump(): motionY == gravity*2");
			return $this->getLevel()->getBlock(new Vector3(Math::floorFloat($this->x), (int) $this->y, Math::floorFloat($this->z))) instanceof Liquid;
		}else{ // dive up?
			if($this->getLevel()->getBlock(new Vector3(Math::floorFloat($this->x), (int) ($this->y + 0.8), Math::floorFloat($this->z))) instanceof Liquid){
				// PureEntities::logOutput("$this: checkJump(): instanceof liquid");
				$this->motion->y = $this->gravity * 2; // set swimming (rather walking on water ;))
				return true;
			}
		}
		if($this->getDirection() === null){ // without a direction jump calculation is not possible!
			// PureEntities::logOutput("$this: checkJump(): no direction given ...");
			echo"Pas de direction";
			return false;
		}
		echo"Testjump2";
		// PureEntities::logOutput("$this: checkJump(): position is [x:" . $this->x . "] [y:" . $this->y . "] [z:" . $this->z . "]");
		// sometimes entities overlap blocks and the current position is already the next block in front ...
		// they overlap especially when following an entity - you can see it when the entity (e.g. creeper) is looking
		// in your direction but cannot jump (is stuck). Then the next line should apply
									$blocks = 2;
									$x = $this->x;
									$z = $this->z;
									$yaw = $this->yaw - 180;
									$deltaX = sin($yaw)*$blocks;
									$deltaZ = cos($yaw)*$blocks;		
		// $blockingBlock = $this->getLevel()->getBlock($this->getPosition());
		$blockingBlock = $this->getLevel()->getBlock(new Vector3(round($deltaX+$x), $this->y, round($deltaZ+$z)));
		var_dump($blockingBlock);
		if($blockingBlock->canPassThrough()){ // when we can pass through the current block then the next block is blocking the way
			echo"canPassThrough";

			try{
									$blocks = 3;
									$x = $this->x;
									$z = $this->z;
									$yaw = $this->yaw - 180;
									$deltaX = sin($yaw)*$blocks;
									$deltaZ = cos($yaw)*$blocks;		
				$blockingBlock = $this->getLevel()->getBlock(new Vector3(round($deltaX+$x), $this->y, round($deltaZ+$z))); // just for correction use 2 blocks ...
			}catch(\InvalidStateException $ex){
				// PureEntities::logOutput("Caught InvalidStateException for getTargetBlock", PureEntities::DEBUG);
				echo"InvalidStateException for getTargetBlock";
				return false;
			}
		}
		var_dump($blockingBlock);
		if($blockingBlock != null and !$blockingBlock->canPassThrough() and 1.2 > 0){ //1.2 -> $this->getMaxJumpHeight()
			echo"On essaye de sauter";

			// we cannot pass through the block that is directly in front of entity - check if jumping is possible
			$upperBlock = $this->getLevel()->getBlock($blockingBlock->add(0, 1, 0));
			$secondUpperBlock = $this->getLevel()->getBlock($blockingBlock->add(0, 2, 0));
			// PureEntities::logOutput("$this: checkJump(): block in front is $blockingBlock, upperBlock is $upperBlock, second Upper block is $secondUpperBlock");
			// check if we can get through the upper of the block directly in front of the entity
			if($upperBlock->canPassThrough() && $secondUpperBlock->canPassThrough()){
				echo"test 1";

				if($blockingBlock instanceof Fence || $blockingBlock instanceof FenceGate){ // cannot pass fence or fence gate ...
					$this->motion->y = $this->gravity;
					// PureEntities::logOutput("$this: checkJump(): found fence or fence gate!", PureEntities::DEBUG);
				}else if($blockingBlock instanceof StoneSlab or $blockingBlock instanceof Stair){ // on stairs entities shouldn't jump THAT high
					$this->motion->y = $this->gravity * 4;
					// PureEntities::logOutput("$this: checkJump(): found slab or stair!", PureEntities::DEBUG);
				}else if($this->motion->y < ($this->gravity * 3.2)){ // Magic
					// PureEntities::logOutput("$this: checkJump(): set motion to gravity * 3.2!", PureEntities::DEBUG);
					$this->motion->y = $this->gravity * 3.2;
				}else{
					// PureEntities::logOutput("$this: checkJump(): nothing else!", PureEntities::DEBUG);
					$this->motion->y += $this->gravity * 0.25;
				}
				return true;
			}elseif(!$upperBlock->canPassThrough()){
				echo"test 2";
				// PureEntities::logOutput("$this: checkJump(): cannot pass through the upper blocks!", PureEntities::DEBUG);
				$this->yaw = $this->getYaw() + mt_rand(-120, 120) / 10;
			}
		}else{
			// PureEntities::logOutput("$this: checkJump(): no need to jump. Block can be passed! [canPassThrough:" . $blockingBlock->canPassThrough() . "] " .
				// "[jumpHeight:" . $this->getMaxJumpHeight() . "] [checkedBlock:" . $blockingBlock . "]", PureEntities::DEBUG);
				echo"test 3";
		}
		return false;
	}



	public function sprayBlood(Boss $entity, $amplifier) : void{
		$amplifier = (int) round($amplifier / 15);
		for($i = 0; $i <= $amplifier; $i ++){
			$entity->getLevel()->addParticle(new DestroyBlockParticle(new Vector3($entity->x, $entity->y, $entity->z), Block::get(152)));
		}
	}

	public function kill():void {
		parent::kill();
		$this->plugin->respawn($this->getNameTag(),$this->respawnTime);
	}

	public function getDrops():array {
		$drops = array();
		foreach($this->drops as $drop){
			if(mt_rand(1,100) <= $drop[1]) $drops[] = $drop[0];
		}
		return $drops;
	}

	public function close(): void
	{
		parent::close();
		$this->plugin = null;
		$this->spawnPos = null;
		$this->drops = [];
		$this->heldItem = null;
	}
	
	public function eventCheck(FloatingTextParticle $particle, Level $level, $event) {
		if ($event instanceof EntityDamageEvent) if ($event->isCancelled ()) return;
		$level->addParticle ( $particle );
		$this->plugin->getScheduler ()->scheduleDelayedTask ( new DeleteParticlesTask ( $this, $particle, $event->getEntity ()->getLevel () ), 20 );
	}
	public function deleteParticles(FloatingTextParticle $particle, Level $level) {
		$particle->setInvisible ();
		$level->addParticle ( $particle );
	}
	

}