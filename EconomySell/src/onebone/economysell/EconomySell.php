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
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\item\StringToItemParser;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\block\tile\Sign;
use pocketmine\block\utils\SignText;

class EconomySell extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var DataProvider */
	private $provider;
	/** @var ItemDisplayer[] */
	private $displayers = [];
	/** @var Config */
	private $lang;
	/** @var array */
	private $tap = [];

	public function onEnable() {
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

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		foreach($this->provider->getAll() as $level => $sells) {
			if(!is_array($sells)) continue;
			foreach($sells as $x => $xData) {
				if(!is_array($xData)) continue;
				foreach($xData as $y => $yData) {
					if(!is_array($yData)) continue;
					foreach($yData as $z => $sell) {
						if(!is_array($sell)) continue;
						$pos = new Position((int)$x, (int)$y, (int)$z, $this->getServer()->getWorldManager()->getWorldByName($level));
						if($pos->getWorld() === null) continue;

						$item = StringToItemParser::getInstance()->parse($sell["item"] ?? "");
						if($item === null) continue;

						$this->displayers[] = new ItemDisplayer($pos, $item, $pos);
					}
				}
			}
		}
	}

	public function onJoin(PlayerJoinEvent $event) {
		$player = $event->getPlayer();
		foreach($this->displayers as $displayer) {
			$displayer->spawnTo($player);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		switch($command->getName()) {
			case "sell":
				if(!$sender instanceof Player) {
					$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
					return false;
				}

				if(!isset($args[0])) {
					$sender->sendMessage(TextFormat::RED . "Usage: /sell <create|remove>");
					return false;
				}

				switch(strtolower($args[0])) {
					case "create":
					case "c":
						if(!$sender->hasPermission("economysell.command.sell.create")) {
							$sender->sendMessage(TextFormat::RED . "You don't have permission to create sell points.");
							return false;
						}

						if(!isset($args[1]) or !isset($args[2])) {
							$sender->sendMessage(TextFormat::RED . "Usage: /sell create <item> <price>");
							return false;
						}

						$item = StringToItemParser::getInstance()->parse($args[1]);
						if($item === null) {
							$sender->sendMessage(TextFormat::RED . "Invalid item: " . $args[1]);
							return false;
						}

						if(!is_numeric($args[2]) or $args[2] < 0) {
							$sender->sendMessage(TextFormat::RED . "Price must be a positive number.");
							return false;
						}

						$price = (float) $args[2];

						$pos = $sender->getPosition();
						$sell = [
							"item" => $args[1],
							"price" => $price,
							"creator" => $sender->getName()
						];

						$event = new SellCreationEvent($this, $sell, $sender);
						$event->call();

						if($event->isCancelled()) {
							return false;
						}

						$this->provider->addSell($pos, $sell);
						$this->displayers[] = new ItemDisplayer($pos, $item, $pos);

						$sender->sendMessage(TextFormat::GREEN . "Sell point created successfully!");
						return true;

					case "remove":
					case "r":
						if(!$sender->hasPermission("economysell.command.sell.remove")) {
							$sender->sendMessage(TextFormat::RED . "You don't have permission to remove sell points.");
							return false;
						}

						$pos = $sender->getPosition();
						$sell = $this->provider->getSell($pos);

						if($sell === null) {
							$sender->sendMessage(TextFormat::RED . "No sell point found at this location.");
							return false;
						}

						if($sell["creator"] !== $sender->getName() and !$sender->hasPermission("economysell.admin")) {
							$sender->sendMessage(TextFormat::RED . "You can only remove your own sell points.");
							return false;
						}

						$this->provider->removeSell($pos);
						$sender->sendMessage(TextFormat::GREEN . "Sell point removed successfully!");
						return true;
				}
				break;
		}
		return false;
	}

	public function onInteract(PlayerInteractEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		if(isset($this->tap[$player->getName()])) {
			$this->tap[$player->getName()] = $pos;
			$player->sendMessage(TextFormat::GREEN . "Position selected! Use /sell create <item> <price> to create a sell point here.");
			$event->cancel();
			return;
		}

		$sell = $this->provider->getSell($pos);
		if($sell === null) return;

		$event->cancel();

		$item = StringToItemParser::getInstance()->parse($sell["item"]);
		if($item === null) return;

		$playerItem = $player->getInventory()->getItemInHand();
		if(!$playerItem->equals($item, true, false)) {
			$player->sendMessage(TextFormat::RED . "You need to hold " . $item->getName() . " to sell here!");
			return;
		}

		if($playerItem->getCount() < 1) {
			$player->sendMessage(TextFormat::RED . "You don't have any " . $item->getName() . " to sell!");
			return;
		}

		$sellAmount = min($playerItem->getCount(), 64);
		$totalPrice = $sell["price"] * $sellAmount;

		$transactionEvent = new SellTransactionEvent($this, $sell, $player, $item, $totalPrice);
		$transactionEvent->call();

		if($transactionEvent->isCancelled()) {
			return;
		}

		$playerItem->setCount($playerItem->getCount() - $sellAmount);
		$player->getInventory()->setItemInHand($playerItem);

		$this->api->addMoney($player, $totalPrice);

		$player->sendMessage(TextFormat::GREEN . "You sold " . $item->getName() . " x" . $sellAmount . " for $" . $totalPrice);
	}

	public function onBreak(BlockBreakEvent $event) {
		$pos = $event->getBlock()->getPosition();
		$sell = $this->provider->getSell($pos);

		if($sell !== null) {
			$player = $event->getPlayer();
			if($sell["creator"] !== $player->getName() and !$player->hasPermission("economysell.admin")) {
				$event->cancel();
				$player->sendMessage(TextFormat::RED . "You cannot break this sell point!");
			} else {
				$this->provider->removeSell($pos);
				$player->sendMessage(TextFormat::GREEN . "Sell point removed!");
			}
		}
	}

	public function onPlace(BlockPlaceEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if($block instanceof Sign) {
			$pos = $block->getPosition();
			$sell = $this->provider->getSell($pos);

			if($sell !== null) {
				$item = StringToItemParser::getInstance()->parse($sell["item"]);
				if($item !== null) {
					$lines = [
						"[SELL]",
						$item->getName(),
						"Price: $" . $sell["price"],
						"Creator: " . $sell["creator"]
					];
					
					$signText = new SignText($lines);
					$block->setText($signText);
				}
			}
		}
	}

	public function onTeleport(EntityTeleportEvent $event) {
		$entity = $event->getEntity();
		if($entity instanceof Player) {
			foreach($this->displayers as $displayer) {
				$displayer->despawnFrom($entity);
				$displayer->spawnTo($entity);
			}
		}
	}

	public function getAPI(): EconomyAPI {
		return $this->api;
	}

	public function getProvider(): DataProvider {
		return $this->provider;
	}

	public function onDisable() {
		if($this->provider !== null) {
			$this->provider->save();
		}
	}
}