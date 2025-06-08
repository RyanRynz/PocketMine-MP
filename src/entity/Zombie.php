<?php

namespace pocketmine\entity;

use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\particle\HugeExplodeParticle;
use pocketmine\world\sound\ExplodeSound;
use function mt_rand;
use function sqrt;

class Zombie extends Living{

    private ?Living $target = null;
    private int $attackDelay = 0;
    private int $burnTime = 0;
    private bool $isBaby = false;
    private bool $canBreakDoors = false;
    private int $conversionTime = -1;

    public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE; }

    protected function getInitialSizeInfo() : EntitySizeInfo{
        return $this->isBaby ? 
            new EntitySizeInfo(0.9, 0.3) :
            new EntitySizeInfo(1.8, 0.6);
    }

    protected function initEntity(CompoundTag $nbt) : void{
        parent::initEntity($nbt);
        $this->isBaby = $nbt->getByte("IsBaby", 0) === 1;
        $this->setMovementSpeed($this->isBaby ? 0.5 : 0.23);
    }

    public function getName() : string{
        return $this->isBaby ? "Baby Zombie" : "Zombie";
    }

    public function onUpdate(int $currentTick): bool{
        if(!parent::onUpdate($currentTick)){
            return false;
        }

        $this->updateSunBurn();
        $this->updateConversion();
        $this->updateTarget();
        $this->updateMovement();
        $this->updateAttack();

        return true;
    }

    protected function updateSunBurn(): void{
        if($this->getWorld()->isDayTime() && !$this->getWorld()->isRaining()){
            $lightLevel = $this->getWorld()->getFullLightAt($this->getPosition()->getFloorX(), $this->getPosition()->getFloorY(), $this->getPosition()->getFloorZ());
            
            if($lightLevel >= 12 && !$this->isUnderwater()){
                if($this->burnTime++ % 20 === 0){
                    $this->setOnFire(1);
                }
            }else{
                $this->burnTime = 0;
            }
        }else{
            $this->extinguish();
            $this->burnTime = 0;
        }
    }

    protected function updateConversion(): void{
        if($this->isUnderwater()){
            if($this->conversionTime === -1){
                $this->conversionTime = 600;
            }elseif($this->conversionTime-- <= 0){
                $this->convertToDrowned();
            }
        }else{
            $this->conversionTime = -1;
        }
    }

    protected function convertToDrowned(): void{
        $this->getWorld()->addParticle($this->getPosition(), new HugeExplodeParticle());
        $this->getWorld()->addSound($this->getPosition(), new ExplodeSound());

        $drowned = new Drowned($this->getLocation(), $this->getSkin());
        $drowned->setHealth($this->getHealth());

        foreach($this->getArmorInventory()->getContents() as $slot => $item){
            $drowned->getArmorInventory()->setItem($slot, $item);
        }
        $drowned->getInventory()->setItemInHand($this->getInventory()->getItemInHand());
        
        $this->flagForDespawn();
        $drowned->spawnToAll();
    }

    protected function updateTarget(): void{
        if($this->target === null || !$this->target->isAlive() || $this->target->getPosition()->distance($this->getPosition()) > 15){
            $this->target = $this->findNearestTarget();
        }
    }

    protected function findNearestTarget(): ?Living{
        $nearest = null;
        $nearestDistance = null;
        
        foreach($this->getWorld()->getEntities() as $entity){
            if($entity instanceof Player || $entity->getName() === "Villager"){
                $distance = $entity->getPosition()->distance($this->getPosition());
                
                if($distance <= 15 && ($nearest === null || $distance < $nearestDistance)){
                    $nearest = $entity;
                    $nearestDistance = $distance;
                }
            }
        }
        
        return $nearest;
    }

    protected function updateMovement(): void{
        if($this->target !== null){
            $direction = $this->target->getPosition()->subtractVector($this->getPosition())->normalize();

            $speed = $this->isBaby ? 0.05 : 0.03;
            $motion = new Vector3(
                $direction->x * $speed,
                $direction->y * $speed,
                $direction->z * $speed
            );
            
            $this->setMotion($motion);
            $this->lookAt($this->target->getPosition());
        }
    }

    protected function updateAttack(): void{
        if($this->target !== null && $this->target->getPosition()->distance($this->getPosition()) <= 1.5){
            if($this->attackDelay <= 0){
                $this->attackTarget();
                $this->attackDelay = 20; // 1 detik cooldown
            }else{
                $this->attackDelay--;
            }
        }
    }

    protected function attackTarget(): void{
        $this->broadcastAnimation(new ArmSwingAnimation($this));
        
        $damage = $this->isBaby ? 2 : 3;
        $knockback = 0.3;
        
        $ev = new EntityDamageByEntityEvent($this, $this->target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage, [], $knockback);
        $this->target->attack($ev);
    }

    public function getDrops() : array{
        $drops = [
            VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 2))
        ];

        if(mt_rand(0, 199) < 5){
            switch(mt_rand(0, 2)){
                case 0:
                    $drops[] = VanillaItems::IRON_INGOT();
                    break;
                case 1:
                    $drops[] = VanillaItems::CARROT();
                    break;
                case 2:
                    $drops[] = VanillaItems::POTATO();
                    break;
            }
        }

        return $drops;
    }

    public function getXpDropAmount() : int{
        return $this->isBaby ? 12 : 5;
    }

    public function getPickedItem() : ?Item{
        return VanillaItems::ZOMBIE_SPAWN_EGG();
    }
}
