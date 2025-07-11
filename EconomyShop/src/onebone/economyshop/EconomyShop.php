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

namespace onebone\economyshop;

use onebone\economyapi\EconomyAPI;
use onebone\economyshop\event\ShopCreationEvent;
use onebone\economyshop\event\ShopTransactionEvent;
use onebone\economyshop\item\ItemDisplayer;
use onebone\economyshop\provider\DataProvider;
use onebone\economyshop\provider\YamlDataProvider;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

class EconomyShop extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var DataProvider */
	private $provider;
	/** @var ItemDisplayer[] */
	private $displayers = [];
	/** @var Config */
	private $lang;

	public function onEnable(): void {
		if(!file_exists($this->getDataFolder())) {
			mkdir($this->getDataFolder());
		}

		$this->api = EconomyAPI::getInstance();
		if($this->api === null) {
			$this->getLogger()->critical("EconomyAPI plugin not found!");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->saveDefaultConfig();
		$this->saveResource("language.yml");
		$this->saveResource("ShopText.yml");

		$this->provider = new YamlDataProvider($this->getDataFolder() . "Shops.yml", true);

		$this->lang = new Config($this->getDataFolder() . "language.yml", Config::YAML);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		foreach($this->provider->getAll() as $shop) {
			$pos = new Position($shop["x"], $shop["y"], $shop["z"], $this->getServer()->getWorldManager()->getWorldByName($shop["level"]));
			if($pos->getWorld() === null) continue;

			$item = StringToItemParser::getInstance()->parse($shop["item"]);
			if($item === null) continue;

			$this->displayers[] = new ItemDisplayer($pos, $item, $pos);
		}

		$this->getLogger()->info("EconomyShop has been enabled");
	}

	public function onDisable(): void {
		foreach($this->displayers as $displayer) {
			$displayer->despawnFromAll();
		}
		$this->provider->save();
	}

	/**
	 * @param PlayerJoinEvent $event
	 * @priority MONITOR
	 * @ignoreCancelled true
	 */
	public function onPlayerJoin(PlayerJoinEvent $event): void {
		foreach($this->displayers as $displayer) {
			$displayer->spawnTo($event->getPlayer());
		}
	}

	/**
	 * @param BlockBreakEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 */
	public function onBlockBreak(BlockBreakEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if($this->provider->shopExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
			$shop = $this->provider->getShop($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
			
			if($shop["owner"] !== strtolower($player->getName()) && !$player->hasPermission("economyshop.admin")) {
				$player->sendMessage($this->getMessage("shop-break-not-owner", $player->getName()));
				$event->cancel();
				return;
			}

			$this->provider->removeShop($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
			
			// Remove displayer
			foreach($this->displayers as $key => $displayer) {
				if($displayer->getLinked()->equals($block->getPosition())) {
					$displayer->despawnFromAll();
					unset($this->displayers[$key]);
					break;
				}
			}

			$player->sendMessage($this->getMessage("shop-removed", $player->getName()));
		}
	}

	/**
	 * @param PlayerInteractEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 */
	public function onPlayerInteract(PlayerInteractEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if($this->provider->shopExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
			$shop = $this->provider->getShop($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
			
			$item = StringToItemParser::getInstance()->parse($shop["item"]);
			if($item === null) {
				$player->sendMessage($this->getMessage("invalid-item", $player->getName()));
				return;
			}

			$price = $shop["price"];
			$stock = $shop["stock"];

			if($stock <= 0) {
				$player->sendMessage($this->getMessage("shop-out-of-stock", $player->getName()));
				return;
			}

			if($this->api->myMoney($player) < $price) {
				$player->sendMessage($this->getMessage("not-enough-money", $player->getName(), [$price]));
				return;
			}

			$ev = new ShopTransactionEvent($this, $player, $shop, $item, $price);
			$ev->call();
			if($ev->isCancelled()) {
				return;
			}

			$this->api->reduceMoney($player, $price);
			$this->api->addMoney($shop["owner"], $price);

			$player->getInventory()->addItem($item);
			$this->provider->setStock($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName(), $stock - 1);

			$player->sendMessage($this->getMessage("shop-bought", $player->getName(), [$item->getName(), $price]));
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "shop") {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
				return true;
			}

			if(!isset($args[0])) {
				$sender->sendMessage(TextFormat::RED . "Usage: /shop <create|remove>");
				return true;
			}

			switch(strtolower($args[0])) {
				case "create":
					if(!$sender->hasPermission("economyshop.create")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to create shops.");
						return true;
					}

					if(!isset($args[1]) || !isset($args[2]) || !isset($args[3])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /shop create <item> <price> <stock>");
						return true;
					}

					$item = StringToItemParser::getInstance()->parse($args[1]);
					if($item === null) {
						$sender->sendMessage(TextFormat::RED . "Invalid item: " . $args[1]);
						return true;
					}

					$price = (float) $args[2];
					$stock = (int) $args[3];

					if($price <= 0 || $stock <= 0) {
						$sender->sendMessage(TextFormat::RED . "Price and stock must be positive numbers.");
						return true;
					}

					$block = $sender->getTargetBlock(5);
					if($block === null) {
						$sender->sendMessage(TextFormat::RED . "You must be looking at a block.");
						return true;
					}

					if($this->provider->shopExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
						$sender->sendMessage(TextFormat::RED . "A shop already exists at this location.");
						return true;
					}

					$ev = new ShopCreationEvent($this, $sender, $block->getPosition(), $item, $price, $stock);
					$ev->call();
					if($ev->isCancelled()) {
						return true;
					}

					$this->provider->addShop($sender->getName(), $item->__toString(), $price, $stock, $block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
					
					$displayer = new ItemDisplayer($block->getPosition()->add(0, 1, 0), $item, $block->getPosition());
					$displayer->spawnToAll($block->getPosition()->getWorld());
					$this->displayers[] = $displayer;

					$sender->sendMessage($this->getMessage("shop-created", $sender->getName(), [$item->getName(), $price, $stock]));
					break;

				case "remove":
					if(!$sender->hasPermission("economyshop.remove")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to remove shops.");
						return true;
					}

					$block = $sender->getTargetBlock(5);
					if($block === null) {
						$sender->sendMessage(TextFormat::RED . "You must be looking at a block.");
						return true;
					}

					if(!$this->provider->shopExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
						$sender->sendMessage(TextFormat::RED . "No shop exists at this location.");
						return true;
					}

					$shop = $this->provider->getShop($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
					
					if($shop["owner"] !== strtolower($sender->getName()) && !$sender->hasPermission("economyshop.admin")) {
						$sender->sendMessage(TextFormat::RED . "You can only remove your own shops.");
						return true;
					}

					$this->provider->removeShop($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
					
					// Remove displayer
					foreach($this->displayers as $key => $displayer) {
						if($displayer->getLinked()->equals($block->getPosition())) {
							$displayer->despawnFromAll();
							unset($this->displayers[$key]);
							break;
						}
					}

					$sender->sendMessage($this->getMessage("shop-removed", $sender->getName()));
					break;

				default:
					$sender->sendMessage(TextFormat::RED . "Usage: /shop <create|remove>");
					break;
			}
			return true;
		}
		return false;
	}

	private function getMessage(string $key, string $player, array $params = []): string {
		$message = $this->lang->get($key, $key);
		
		foreach($params as $i => $param) {
			$message = str_replace("{%" . ($i + 1) . "}", $param, $message);
		}
		
		return TextFormat::colorize($message);
	}
}