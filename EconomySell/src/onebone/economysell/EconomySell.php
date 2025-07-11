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

namespace onebone\economysell;

use onebone\economyapi\EconomyAPI;
use onebone\economysell\event\SellCreationEvent;
use onebone\economysell\event\SellTransactionEvent;
use onebone\economysell\item\ItemDisplayer;
use onebone\economysell\provider\DataProvider;
use onebone\economysell\provider\YamlDataProvider;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class EconomySell extends PluginBase implements Listener {
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
		$this->saveResource("ShopText.yml");

		$this->provider = new YamlDataProvider($this->getDataFolder() . "Sells.yml", true);

		$this->lang = new Config($this->getDataFolder() . "lang_en.json", Config::JSON);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		foreach($this->provider->getAll() as $sell) {
			$pos = new Position($sell["x"], $sell["y"], $sell["z"], $this->getServer()->getWorldManager()->getWorldByName($sell["level"]));
			if($pos->getWorld() === null) continue;

			$item = StringToItemParser::getInstance()->parse($sell["item"]);
			if($item === null) continue;

			$this->displayers[] = new ItemDisplayer($pos, $item, $pos);
		}

		$this->getLogger()->info("EconomySell has been enabled");
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

		if($this->provider->sellExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
			$sell = $this->provider->getSell($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
			
			if($sell["owner"] !== strtolower($player->getName()) && !$player->hasPermission("economysell.admin")) {
				$player->sendMessage($this->getMessage("sell-break-not-owner", $player->getName()));
				$event->cancel();
				return;
			}

			$this->provider->removeSell($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
			
			// Remove displayer
			foreach($this->displayers as $key => $displayer) {
				if($displayer->getLinked()->equals($block->getPosition())) {
					$displayer->despawnFromAll();
					unset($this->displayers[$key]);
					break;
				}
			}

			$player->sendMessage($this->getMessage("sell-removed", $player->getName()));
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
		$item = $player->getInventory()->getItemInHand();

		if($this->provider->sellExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
			$sell = $this->provider->getSell($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
			
			$sellItem = StringToItemParser::getInstance()->parse($sell["item"]);
			if($sellItem === null) {
				$player->sendMessage($this->getMessage("invalid-item", $player->getName()));
				return;
			}

			if(!$item->equals($sellItem, true, false)) {
				$player->sendMessage($this->getMessage("wrong-item", $player->getName(), [$sellItem->getName()]));
				return;
			}

			$price = $sell["price"];
			$amount = min($item->getCount(), $sellItem->getCount());

			$totalPrice = $price * $amount;

			$ev = new SellTransactionEvent($this, $player, $sell, $item, $totalPrice);
			$ev->call();
			if($ev->isCancelled()) {
				return;
			}

			$this->api->addMoney($player, $totalPrice);
			$player->getInventory()->removeItem($item->setCount($amount));

			$player->sendMessage($this->getMessage("item-sold", $player->getName(), [$amount, $sellItem->getName(), $totalPrice]));
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "sell") {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
				return true;
			}

			if(!isset($args[0])) {
				$sender->sendMessage(TextFormat::RED . "Usage: /sell <create|remove>");
				return true;
			}

			switch(strtolower($args[0])) {
				case "create":
					if(!$sender->hasPermission("economysell.create")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to create sell points.");
						return true;
					}

					if(!isset($args[1]) || !isset($args[2])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /sell create <item> <price>");
						return true;
					}

					$item = StringToItemParser::getInstance()->parse($args[1]);
					if($item === null) {
						$sender->sendMessage(TextFormat::RED . "Invalid item: " . $args[1]);
						return true;
					}

					$price = (float) $args[2];

					if($price <= 0) {
						$sender->sendMessage(TextFormat::RED . "Price must be a positive number.");
						return true;
					}

					$block = $sender->getTargetBlock(5);
					if($block === null) {
						$sender->sendMessage(TextFormat::RED . "You must be looking at a block.");
						return true;
					}

					if($this->provider->sellExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
						$sender->sendMessage(TextFormat::RED . "A sell point already exists at this location.");
						return true;
					}

					$ev = new SellCreationEvent($this, $sender, $block->getPosition(), $item, $price);
					$ev->call();
					if($ev->isCancelled()) {
						return true;
					}

					$this->provider->addSell($sender->getName(), $item->__toString(), $price, $block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
					
					$displayer = new ItemDisplayer($block->getPosition()->add(0, 1, 0), $item, $block->getPosition());
					$displayer->spawnToAll($block->getPosition()->getWorld());
					$this->displayers[] = $displayer;

					$sender->sendMessage($this->getMessage("sell-created", $sender->getName(), [$item->getName(), $price]));
					break;

				case "remove":
					if(!$sender->hasPermission("economysell.remove")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to remove sell points.");
						return true;
					}

					$block = $sender->getTargetBlock(5);
					if($block === null) {
						$sender->sendMessage(TextFormat::RED . "You must be looking at a block.");
						return true;
					}

					if(!$this->provider->sellExists($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName())) {
						$sender->sendMessage(TextFormat::RED . "No sell point exists at this location.");
						return true;
					}

					$sell = $this->provider->getSell($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
					
					if($sell["owner"] !== strtolower($sender->getName()) && !$sender->hasPermission("economysell.admin")) {
						$sender->sendMessage(TextFormat::RED . "You can only remove your own sell points.");
						return true;
					}

					$this->provider->removeSell($block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(), $block->getPosition()->getWorld()->getFolderName());
					
					// Remove displayer
					foreach($this->displayers as $key => $displayer) {
						if($displayer->getLinked()->equals($block->getPosition())) {
							$displayer->despawnFromAll();
							unset($this->displayers[$key]);
							break;
						}
					}

					$sender->sendMessage($this->getMessage("sell-removed", $sender->getName()));
					break;

				default:
					$sender->sendMessage(TextFormat::RED . "Usage: /sell <create|remove>");
					break;
			}
			return true;
		}
		return false;
	}

	private function getMessage(string $key, string $player, array $params = []): string {
		$messages = $this->lang->getAll();
		$message = $messages[$key] ?? $key;
		
		foreach($params as $i => $param) {
			$message = str_replace("{%" . ($i + 1) . "}", $param, $message);
		}
		
		return TextFormat::colorize($message);
	}
}