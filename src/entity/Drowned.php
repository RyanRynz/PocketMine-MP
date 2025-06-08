<?php

namespace pocketmine\entity;

use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use function mt_rand;

class Drowned extends Zombie{

    private int $tridentAttackCooldown = 0;
    private bool $hasTrident = false;

    public static function getNetworkTypeId() : string{ return EntityIds::DROWNED; }

    protected function initEntity(CompoundTag $nbt) : void{
        parent::initEntity($nbt);
        
        // 15% chance memiliki trident
        $this->hasTrident = mt_rand(1, 100) <= 15;
        $this->setMovementSpeed(0.23); // Kecepatan di air sama seperti zombie di darat
    }

    public function getName() : string{
        return "Drowned";
    }

    protected function updateMovement(): void{
        if($this->target !== null){
            $direction = $this->target->getPosition()->subtractVector($this->getPosition())->normalize();
            
            // Gerakan lebih halus di air
            $speed = $this->isUnderwater() ? 0.04 : 0.03;
            
            $motion = new Vector3(
                $direction->x * $speed,
                $this->isUnderwater() ? $direction->y * $speed : 0,
                $direction->z * $speed
            );
            
            $this->setMotion($motion);
            $this->lookAt($this->target->getPosition());
        }
    }

    protected function updateAttack(): void{
        if($this->target === null || $this->target->getPosition()->distance($this->getPosition()) > 1.5){
            return;
        }

        if($this->attackDelay <= 0){
            $this->attackTarget();
            $this->attackDelay = 20; // 1 detik cooldown untuk serangan normal
        }else{
            $this->attackDelay--;
        }

        // Serangan trident khusus
        if($this->hasTrident && $this->tridentAttackCooldown <= 0){
            $this->rangedAttack();
            $this->tridentAttackCooldown = 100; // 5 detik cooldown untuk trident
        }else{
            $this->tridentAttackCooldown--;
        }
    }

    protected function rangedAttack(): void{
        if($this->target === null || $this->target->getPosition()->distance($this->getPosition()) > 8){
            return;
        }

        $this->broadcastAnimation(new ArmSwingAnimation($this));
        
        // Buat trident entity
        $trident = new ThrownTrident(
            $this->getLocation(),
            $this,
            $this->getDirectionVector()->multiply(1.5)
        );
        $trident->setPickupMode(ThrownTrident::PICKUP_NONE); // Drowned trident tidak bisa diambil
        $trident->spawnToAll();
    }

    protected function attackTarget(): void{
        $this->broadcastAnimation(new ArmSwingAnimation($this));
        
        $damage = $this->isBaby ? 3 : 4; // Drowned lebih kuat dari zombie biasa
        $knockback = 0.3;
        
        $ev = new EntityDamageByEntityEvent($this, $this->target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage, [], $knockback);
        $this->target->attack($ev);
    }

    public function getDrops() : array{
        $drops = parent::getDrops();
        
        // Drowned bisa drop copper ingot
        if(mt_rand(0, 199) < 5){
            $drops[] = VanillaItems::COPPER_INGOT();
        }
        
        // Drop trident jika memilikinya (11% chance)
        if($this->hasTrident && mt_rand(1, 100) <= 11){
            $drops[] = VanillaItems::TRIDENT();
        }
        
        return $drops;
    }

    public function getXpDropAmount() : int{
        return $this->isBaby ? 12 : 5;
    }

    public function getPickedItem() : ?Item{
        return VanillaItems::DROWNED_SPAWN_EGG();
    }

    protected function updateConversion(): void{
      // Cannot change back
    }
}
