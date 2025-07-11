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

namespace onebone\economyapi\internal;

use onebone\economyapi\currency\Currency;
use onebone\economyapi\EconomyAPI;
use onebone\economyapi\provider\Provider;
use onebone\economyapi\provider\RevertAction;
use onebone\economyapi\util\Promise;
use onebone\economyapi\util\Transaction;
use onebone\economyapi\util\TransactionAction;
use onebone\economyapi\util\TransactionResult;
use pocketmine\player\Player;

/**
 * @internal
 * Holds reference to various Providers and returns appropriate values for the request
 *
 * THIS IS INTERNAL CLASS, AND IS SUBJECT TO CHANGE ANYTIME WITHOUT NOTICE.
 */
class BalanceRepository {
	/** @var Provider */
	private $provider;
	/** @var Currency */
	private $currency;
	/** @var ReversionProvider */
	private $reversionProvider;

	public function __construct(Provider $provider, Currency $currency, ReversionProvider $reversionProvider) {
		$this->provider = $provider;
		$this->currency = $currency;
		$this->reversionProvider = $reversionProvider;
	}

	/**
	 * @param string|Player $player
	 * @return bool
	 */
	public function hasAccount($player): bool {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		return $this->provider->accountExists($player);
	}

	/**
	 * @param string|Player $player
	 * @param float $defaultMoney
	 * @return bool
	 */
	public function createAccount($player, float $defaultMoney = 1000): bool {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		return $this->provider->createAccount($player, $defaultMoney);
	}

	/**
	 * @param string|Player $player
	 * @return float
	 */
	public function getMoney($player): float {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		return $this->provider->getMoney($player);
	}

	/**
	 * @param string|Player $player
	 * @param float $amount
	 * @param RevertAction|null $revertAction
	 * @return int
	 */
	public function setMoney($player, float $amount, ?RevertAction &$revertAction = null): int {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		$oldMoney = $this->provider->getMoney($player);
		$result = $this->provider->setMoney($player, $amount);
		
		if($result === EconomyAPI::RET_SUCCESS) {
			$revertAction = new RevertAction(RevertAction::ADD, $player, $oldMoney - $amount);
		}

		return $result;
	}

	/**
	 * @param string|Player $player
	 * @param float $amount
	 * @param RevertAction|null $revertAction
	 * @return int
	 */
	public function addMoney($player, float $amount, ?RevertAction &$revertAction = null): int {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		$result = $this->provider->addMoney($player, $amount);
		
		if($result === EconomyAPI::RET_SUCCESS) {
			$revertAction = new RevertAction(RevertAction::REDUCE, $player, $amount);
		}

		return $result;
	}

	/**
	 * @param string|Player $player
	 * @param float $amount
	 * @param RevertAction|null $revertAction
	 * @return int
	 */
	public function reduceMoney($player, float $amount, ?RevertAction &$revertAction = null): int {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		$result = $this->provider->reduceMoney($player, $amount);
		
		if($result === EconomyAPI::RET_SUCCESS) {
			$revertAction = new RevertAction(RevertAction::ADD, $player, $amount);
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public function getAllBalances(): array {
		return $this->provider->getAll();
	}

	/**
	 * @param int $page
	 * @param int $entriesPerPage
	 * @return array
	 */
	public function getTopBalances(int $page = 1, int $entriesPerPage = 10): array {
		$all = $this->provider->getAll();
		arsort($all);
		
		$offset = ($page - 1) * $entriesPerPage;
		return array_slice($all, $offset, $entriesPerPage, true);
	}
}