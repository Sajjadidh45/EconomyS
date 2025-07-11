<?php

/*
 * EconomyS, the massive economy plugin with many features for PocketMine-MP
 * Copyright (C) 2013-2021  onebone <me@onebone.me>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace onebone\economyland;

use onebone\economyland\land\LandOption;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

class EventListener implements Listener {
	/** @var EconomyLand */
	private $plugin;

	public function __construct(EconomyLand $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * @param BlockBreakEvent $event
	 * @priority HIGH
	 * @ignoreCancelled true
	 */
	public function onBlockBreak(BlockBreakEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		$land = $this->plugin->getLandManager()->getLandByPosition($pos->getFloorX(), $pos->getFloorZ(), $pos->getWorld()->getFolderName());
		
		if($land !== null) {
			if(!$land->isOwner($player) && !$land->canInteract($player)) {
				if(!$land->getOption()->canBreak()) {
					$player->sendMessage(TextFormat::RED . $this->plugin->getMessage("land-no-break"));
					$event->cancel();
				}
			}
		}
	}

	/**
	 * @param BlockPlaceEvent $event
	 * @priority HIGH
	 * @ignoreCancelled true
	 */
	public function onBlockPlace(BlockPlaceEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		$land = $this->plugin->getLandManager()->getLandByPosition($pos->getFloorX(), $pos->getFloorZ(), $pos->getWorld()->getFolderName());
		
		if($land !== null) {
			if(!$land->isOwner($player) && !$land->canInteract($player)) {
				if(!$land->getOption()->canPlace()) {
					$player->sendMessage(TextFormat::RED . $this->plugin->getMessage("land-no-place"));
					$event->cancel();
				}
			}
		}
	}

	/**
	 * @param PlayerInteractEvent $event
	 * @priority HIGH
	 * @ignoreCancelled true
	 */
	public function onPlayerInteract(PlayerInteractEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		$land = $this->plugin->getLandManager()->getLandByPosition($pos->getFloorX(), $pos->getFloorZ(), $pos->getWorld()->getFolderName());
		
		if($land !== null) {
			if(!$land->isOwner($player) && !$land->canInteract($player)) {
				if(!$land->getOption()->canInteract()) {
					$player->sendMessage(TextFormat::RED . $this->plugin->getMessage("land-no-interact"));
					$event->cancel();
				}
			}
		}
	}

	/**
	 * @param EntityDamageByEntityEvent $event
	 * @priority HIGH
	 * @ignoreCancelled true
	 */
	public function onEntityDamage(EntityDamageByEntityEvent $event): void {
		$entity = $event->getEntity();
		$damager = $event->getDamager();

		if($entity instanceof Player && $damager instanceof Player) {
			$pos = $entity->getPosition();
			$land = $this->plugin->getLandManager()->getLandByPosition($pos->getFloorX(), $pos->getFloorZ(), $pos->getWorld()->getFolderName());
			
			if($land !== null) {
				if(!$land->isOwner($damager) && !$land->canInteract($damager)) {
					if(!$land->getOption()->canPvP()) {
						$damager->sendMessage(TextFormat::RED . $this->plugin->getMessage("land-no-pvp"));
						$event->cancel();
					}
				}
			}
		}
	}
}