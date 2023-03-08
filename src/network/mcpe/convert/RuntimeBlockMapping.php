<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\data\bedrock\block\BlockStateSerializer;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

/**
 * @internal
 */
final class RuntimeBlockMapping{
	use SingletonTrait;

	/**
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $networkIdCache = [];

	/** @var BlockStateData[] Used when a blockstate can't be correctly serialized (e.g. because it's unknown) */
	private array $fallbackStateData;
	/** @var int[] */
	private array $fallbackStateId;

	private const BLOCK_PALETTE_PATH = 0;
	private const META_MAP_PATH = 1;

	private static function make() : self{
		$protocolPaths = [
			ProtocolInfo::CURRENT_PROTOCOL => [
				self::BLOCK_PALETTE_PATH => BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT,
				self::META_MAP_PATH => BedrockDataFiles::BLOCK_STATE_META_MAP_JSON,
			],
			ProtocolInfo::PROTOCOL_1_19_50 => [
				self::BLOCK_PALETTE_PATH => BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT_1_19_50,
				self::META_MAP_PATH => BedrockDataFiles::BLOCK_STATE_META_MAP_JSON_1_19_50,
			],
			ProtocolInfo::PROTOCOL_1_19_20 => [
				self::BLOCK_PALETTE_PATH => BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT_1_19_20,
				self::META_MAP_PATH => BedrockDataFiles::BLOCK_STATE_META_MAP_JSON_1_19_20,
			],
			ProtocolInfo::PROTOCOL_1_19_0 => [
				self::BLOCK_PALETTE_PATH => BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT_1_19_0,
				self::META_MAP_PATH => BedrockDataFiles::BLOCK_STATE_META_MAP_JSON_1_19_0,
			]
		];

		$blockStateDictionaries = [];

		foreach($protocolPaths as $mappingProtocol => $paths){
			$canonicalBlockStatesRaw = Filesystem::fileGetContents($paths[self::BLOCK_PALETTE_PATH]);
			$metaMappingRaw = Filesystem::fileGetContents($paths[self::META_MAP_PATH]);

			$blockStateDictionaries[$mappingProtocol] = BlockStateDictionary::loadFromString($canonicalBlockStatesRaw, $metaMappingRaw);
		}

		return new self(
			$blockStateDictionaries,
			GlobalBlockStateHandlers::getSerializer()
		);
	}

	/**
	 * @param BlockStateDictionary[] $blockStateDictionaries
	 */
	public function __construct(
		private array $blockStateDictionaries,
		private BlockStateSerializer $blockStateSerializer
	){
		foreach($this->blockStateDictionaries as $mappingProtocol => $blockStateDictionary){
			$this->fallbackStateId[$mappingProtocol] = $blockStateDictionary->lookupStateIdFromData(
					BlockStateData::current(BlockTypeNames::INFO_UPDATE, [])
				) ?? throw new AssumptionFailedError(BlockTypeNames::INFO_UPDATE . " should always exist");
			//lookup the state data from the dictionary to avoid keeping two copies of the same data around
			$this->fallbackStateData[$mappingProtocol] = $blockStateDictionary->getDataFromStateId($this->fallbackStateId[$mappingProtocol]) ?? throw new AssumptionFailedError("We just looked up this state data, so it must exist");
		}
	}

	public function toRuntimeId(int $internalStateId, int $mappingProtocol) : int{
		if(isset($this->networkIdCache[$mappingProtocol][$internalStateId])){
			return $this->networkIdCache[$mappingProtocol][$internalStateId];
		}

		try{
			$blockStateData = $this->blockStateSerializer->serialize($internalStateId);

			$networkId = $this->getBlockStateDictionary($mappingProtocol)->lookupStateIdFromData($blockStateData);
			if($networkId === null){
				throw new AssumptionFailedError("Unmapped blockstate returned by blockstate serializer: " . $blockStateData->toNbt());
			}
		}catch(BlockStateSerializeException){
			//TODO: this will swallow any error caused by invalid block properties; this is not ideal, but it should be
			//covered by unit tests, so this is probably a safe assumption.
			$networkId = $this->fallbackStateId[$mappingProtocol];
		}

		return $this->networkIdCache[$mappingProtocol][$internalStateId] = $networkId;
	}

	/**
	 * Looks up the network state data associated with the given internal state ID.
	 */
	public function toStateData(int $internalStateId, int $mappingProtocol = ProtocolInfo::CURRENT_PROTOCOL) : BlockStateData{
		//we don't directly use the blockstate serializer here - we can't assume that the network blockstate NBT is the
		//same as the disk blockstate NBT, in case we decide to have different world version than network version (or in
		//case someone wants to implement multi version).
		$networkRuntimeId = $this->toRuntimeId($internalStateId, $mappingProtocol);

		return $this->blockStateDictionaries[$mappingProtocol]->getDataFromStateId($networkRuntimeId) ?? throw new AssumptionFailedError("We just looked up this state ID, so it must exist");
	}

	public function getBlockStateDictionary(int $mappingProtocol) : BlockStateDictionary{ return $this->blockStateDictionaries[$mappingProtocol] ?? throw new AssumptionFailedError("Missing block state dictionary for protocol $mappingProtocol"); }

	public function getFallbackStateData(int $mappingProtocol) : BlockStateData{ return $this->fallbackStateData[$mappingProtocol]; }

	public static function getMappingProtocol(int $protocolId) : int{
		if($protocolId === ProtocolInfo::PROTOCOL_1_19_60){
			return ProtocolInfo::PROTOCOL_1_19_63;
		}
		if($protocolId <= ProtocolInfo::PROTOCOL_1_19_10){
			return ProtocolInfo::PROTOCOL_1_19_0;
		}
		if($protocolId <= ProtocolInfo::PROTOCOL_1_19_40){
			return ProtocolInfo::PROTOCOL_1_19_20;
		}
		return $protocolId;
	}

	/**
	 * @param Player[] $players
	 *
	 * @return Player[][]
	 */
	public static function sortByProtocol(array $players) : array{
		$sortPlayers = [];

		foreach($players as $player){
			$mappingProtocol = self::getMappingProtocol($player->getNetworkSession()->getProtocolId());

			if(isset($sortPlayers[$mappingProtocol])){
				$sortPlayers[$mappingProtocol][] = $player;
			}else{
				$sortPlayers[$mappingProtocol] = [$player];
			}
		}

		return $sortPlayers;
	}
}
