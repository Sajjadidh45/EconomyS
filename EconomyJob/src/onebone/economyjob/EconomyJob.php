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

namespace onebone\economyjob;

use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class EconomyJob extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var Config */
	private $jobs;
	/** @var Config */
	private $players;

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

		$this->saveResource("jobs.yml");
		$this->jobs = new Config($this->getDataFolder() . "jobs.yml", Config::YAML);
		$this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "job") {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
				return false;
			}

			if(!isset($args[0])) {
				$sender->sendMessage(TextFormat::RED . "Usage: /job <join|retire|list|detail|me>");
				return false;
			}

			switch(strtolower($args[0])) {
				case "join":
					if(!isset($args[1])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /job join <job>");
						return false;
					}

					$job = strtolower($args[1]);
					if(!$this->jobs->exists($job)) {
						$sender->sendMessage(TextFormat::RED . "Job '$job' does not exist.");
						return false;
					}

					if($this->getPlayerJob($sender->getName()) !== null) {
						$sender->sendMessage(TextFormat::RED . "You already have a job. Use /job retire first.");
						return false;
					}

					$this->setPlayerJob($sender->getName(), $job);
					$sender->sendMessage(TextFormat::GREEN . "You joined job: " . $job);
					return true;

				case "retire":
					if($this->getPlayerJob($sender->getName()) === null) {
						$sender->sendMessage(TextFormat::RED . "You don't have a job.");
						return false;
					}

					$this->setPlayerJob($sender->getName(), null);
					$sender->sendMessage(TextFormat::GREEN . "You retired from your job.");
					return true;

				case "list":
					$sender->sendMessage(TextFormat::YELLOW . "Available jobs:");
					foreach($this->jobs->getAll() as $jobName => $jobData) {
						$sender->sendMessage(TextFormat::AQUA . "- " . $jobName);
					}
					return true;

				case "detail":
					if(!isset($args[1])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /job detail <job>");
						return false;
					}

					$job = strtolower($args[1]);
					if(!$this->jobs->exists($job)) {
						$sender->sendMessage(TextFormat::RED . "Job '$job' does not exist.");
						return false;
					}

					$jobData = $this->jobs->get($job);
					$sender->sendMessage(TextFormat::YELLOW . "Job: " . $job);
					$sender->sendMessage(TextFormat::AQUA . "Description: " . ($jobData["description"] ?? "No description"));
					$sender->sendMessage(TextFormat::AQUA . "Salary: $" . ($jobData["salary"] ?? 0));
					return true;

				case "me":
					$job = $this->getPlayerJob($sender->getName());
					if($job === null) {
						$sender->sendMessage(TextFormat::RED . "You don't have a job.");
					} else {
						$sender->sendMessage(TextFormat::GREEN . "Your job: " . $job);
					}
					return true;
			}
		}
		return false;
	}

	public function onBlockBreak(BlockBreakEvent $event) {
		$player = $event->getPlayer();
		$job = $this->getPlayerJob($player->getName());

		if($job !== null) {
			$jobData = $this->jobs->get($job);
			if(isset($jobData["break"])) {
				$blockName = $event->getBlock()->getName();
				if(isset($jobData["break"][$blockName])) {
					$reward = $jobData["break"][$blockName];
					$this->api->addMoney($player, $reward);
					$player->sendMessage(TextFormat::GREEN . "You earned $" . $reward . " from your job!");
				}
			}
		}
	}

	public function onBlockPlace(BlockPlaceEvent $event) {
		$player = $event->getPlayer();
		$job = $this->getPlayerJob($player->getName());

		if($job !== null) {
			$jobData = $this->jobs->get($job);
			if(isset($jobData["place"])) {
				$blockName = $event->getBlock()->getName();
				if(isset($jobData["place"][$blockName])) {
					$reward = $jobData["place"][$blockName];
					$this->api->addMoney($player, $reward);
					$player->sendMessage(TextFormat::GREEN . "You earned $" . $reward . " from your job!");
				}
			}
		}
	}

	private function getPlayerJob(string $playerName): ?string {
		return $this->players->get(strtolower($playerName));
	}

	private function setPlayerJob(string $playerName, ?string $job): void {
		if($job === null) {
			$this->players->remove(strtolower($playerName));
		} else {
			$this->players->set(strtolower($playerName), $job);
		}
		$this->players->save();
	}

	public function onDisable() {
		if($this->players !== null) {
			$this->players->save();
		}
	}
}
