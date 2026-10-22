"""
Local-rules notes for the 51 destination pages.

These are the only copy on the site that says anything about law, so they
are held to a different standard from the intros.

Every statement is drawn from one source: the Public Health Law Center's
"U.S. E-Cigarette Regulation: A 50-State Review", which cites the statute
behind each entry and covers all 50 states plus DC in a consistent format.
It was chosen over the dozen retailer blogs that rank for "vape laws by
state" because those disagree with each other -- on flavour restrictions
alone, three of them give three different lists. fetch_state_law.py
collects the per-state pages and build_state_intros.py refuses to write the
import file unless every statute cited below appears in the source text for
that state, and unless each note's claims about tax, flavours and
direct-to-consumer sales match what the source actually says.

Two things are federal rather than state, and where a note mentions them it
says so:

  * 21 is the minimum age for buying any tobacco product including
    e-cigarettes, everywhere, since 20 December 2019 (FDA, "Tobacco 21").
    The review summarises state law only, and several states still have a
    lower figure on their books -- Alaska 19, Missouri, Montana and
    Wisconsin 18. Those are superseded, and a note that mentions one says
    that plainly rather than leaving a reader to think 19 will do.
  * The PACT Act is why vape mail works the way it does at all. It is not
    repeated across 51 pages; it belongs on one explainer the pages can
    link to.

What these notes do not do is tell anyone what they may lawfully do. They
describe published rules, with the source and the date rendered beneath
them by the archive template, and they say the rules change.

The five states where the source records that direct-to-consumer remote
sales are prohibited or confined to licensed trade -- Nebraska, Oregon,
Utah, Vermont and Virginia -- lead with that, because it is the single most
consequential thing on the page and it sits against intros that count how
many shops say they will post there.
"""

# state -> (note, date checked). The source URL is derived from the state
# code in build_state_intros.py, so it cannot drift from the page the note
# was written against.
CHECKED = "2026-10-22"

NOTES = {

    "Alabama": (
        "Alabama requires a retailer to hold a delivery-sale permit before "
        "posting vapor products to an address in the state, to verify the "
        "buyer's age and address through a third-party service, and to take "
        "payment by a credit or debit card in the buyer's own name. That is "
        "why checkout here asks for more than it does elsewhere. A tax of "
        "$0.10 per millilitre on consumable vapor products begins on 1 "
        "October 2026 under Act 2025-377. Retail sale requires a state "
        "licence (Ala. Code § 28-11-7). Alabama's flavour rule restricts "
        "billboard advertising rather than sales."
    ),

    "Alaska": (
        "Alaska charges no state excise tax on e-cigarettes, so the shelf "
        "price is not inflated the way it is in neighbouring Washington or "
        "Oregon. A state licence is required to sell (Alaska Stat. § "
        "43.70.075(a)). One oddity worth understanding: the figure in "
        "Alaska's own statute book is 19. It was left behind by the federal "
        "change of December 2019, which set 21 for every tobacco product "
        "with e-cigarettes included, and the federal figure is the one that "
        "governs. No statewide flavour restriction is recorded."
    ),

    "Arizona": (
        "Arizona's entry in the 50-state review is the shortest of any "
        "destination on this site. It runs to two provisions: do not sell a "
        "vapor product to anyone under 21, and do not buy one if you are "
        "under 21. Nothing on tax, nothing on licensing, nothing on "
        "flavours, nothing on delivery. In practice the quoted price is "
        "close to the price you pay, and the age check is the only step "
        "between you and a delivery. The six shops that turn Arizona down "
        "are doing so by choice."
    ),

    "Arkansas": (
        "Arkansas levies no state excise tax on vapor products, but it is "
        "one of the more heavily permitted states: separate permits are "
        "required for wholesalers, wholesaler salespersons, retailers and "
        "vending machine sales (Ark. Code Ann. §§ 26-57-214, 26-57-215(b)). "
        "The age floor is 21, with a narrow carve-out for active-duty "
        "military and for anyone who had turned 19 by the cut-off date in "
        "the statute. No statewide flavour restriction is recorded. Ten "
        "shops here exclude Arkansas, which is a commercial decision rather "
        "than a legal bar."
    ),

    "California": (
        "California prohibits the sale of flavoured e-cigarettes and flavour "
        "enhancers outright, with a narrow exception for certain hookah "
        "businesses. If you are shopping for anything other than tobacco "
        "flavour, that is the rule behind most refused California orders. "
        "Delivery is restricted too: posting e-cigarettes through the USPS "
        "or any other postal service, including to fill orders placed "
        "online, is prohibited unless the buyer's age is verified as 21 or "
        "over. E-cigarettes are taxed as other tobacco products at 56.32% "
        "of wholesale cost, and retail requires a licence (Cal. Bus. & "
        "Prof. Code §§ 22972)."
    ),

    "Colorado": (
        "Colorado's nicotine tax has been climbing on a published schedule "
        "since 2021 -- 30% of the manufacturer's list price that year, 35% "
        "in 2022, 50% by the later steps -- so Colorado prices sit well "
        "above the low-tax states. Delivery sales may only be made after "
        "verifying that the person receiving the parcel is 21 or over "
        "against government-issued photographic identification, which means "
        "somebody has to be at the door with ID. Both retailers and "
        "distributors need licences (Colo. Rev. Stat. §§ 44-7-104.5(1)(a)(I), "
        "39-28.6-105). No statewide flavour restriction is recorded."
    ),

    "Connecticut": (
        "Connecticut is specific about what has to be on the box. A "
        "delivery sale must go to an age-verified buyer and the container "
        "must carry a shipping label reading \"CONTAINS AN ELECTRONIC "
        "NICOTINE DELIVERY SYSTEM OR VAPOR PRODUCT—SIGNATURE OF A PERSON "
        "AGE 21 OR OLDER REQUIRED FOR DELIVERY\". Tax is $0.40 per "
        "millilitre on prefilled sealed products and 10% of wholesale on "
        "everything else. Retailers and manufacturers must be registered, "
        "and a dealer registration will not issue until the dealer has "
        "completed a state prevention education course (Conn. Gen. Stat. §§ "
        "21a-415(b), 21a-415a(a))."
    ),

    "Delaware": (
        "Delaware's tax is among the lowest in the country at $0.05 per "
        "fluid millilitre (Del. Code Ann. tit. 30, § 5305(c)(2)), and the "
        "licensing position is split: a licence is required to sell "
        "e-liquid, but not to sell devices. Delivery sales need more from "
        "the buyer than most states ask -- a copy of valid government "
        "identification, an attestation that the identification is true and "
        "that the buyer understands the minimum age, delivery to the "
        "address on that identification, and payment by a card in the "
        "buyer's name. No statewide flavour restriction is recorded."
    ),

    "District of Columbia": (
        "The District prohibits the sale of flavoured electronic smoking "
        "devices, so tobacco flavour is the practical limit here however "
        "wide the catalogue looks. It also carries the heaviest tax in this "
        "directory: 79% of wholesale price (D.C. Code § 47-2402.01(a)(1)(C)), "
        "which is why identical hardware can cost noticeably more on a DC "
        "address than across either border. Licences are required to "
        "manufacture, retail, wholesale and vend (D.C. Code § 47-2404(a)). "
        "Every shop in this directory will deliver here, but what they may "
        "lawfully sell you is narrower than the list suggests."
    ),

    "Florida": (
        "Florida charges no state excise tax on vapor products, which puts "
        "it among the cheapest states to buy into, and no statewide flavour "
        "restriction is recorded. What it does require is a chain of checks "
        "on delivery sales of nicotine products: a certification from the "
        "buyer, the retailer's verification of it, notice of the age "
        "restriction, confirmation of the shipment to the buyer by "
        "telephone, and a delivery service that verifies the age of whoever "
        "accepts the parcel. Retail requires a licence (Fla. Stat. §§ "
        "569.32(1)(a), 569.34(1))."
    ),

    "Georgia": (
        "Georgia requires that sales of vapor products to anyone other than "
        "a licensed trade buyer be made in person, face to face. Delivery "
        "sales are allowed but hedged: the delivery must be made by someone "
        "at least 21, after a background check, to a person who is present "
        "to receive it and is also at least 21. That is a materially higher "
        "bar than a signature. Tax is $0.05 per fluid millilitre on closed "
        "systems, with open systems and devices taxed separately (Ga. Code "
        "Ann. § 48-11-2(a)(5)), and licences cover retail, manufacture, "
        "vending and distribution."
    ),

    "Hawaii": (
        "Hawaii requires face-to-face sales of electronic smoking devices, "
        "excepting duty-free facilities and adult-only tobacco businesses, "
        "and has its own delivery-sales provision at Haw. Rev. Stat. § "
        "712-1258(1). Read with the 70% of wholesale price tax that has "
        "applied since 1 January 2024 (Haw. Rev. Stat. § 245-3(a)(14)), "
        "Hawaii is one of the harder and more expensive states to buy vapour "
        "products into, before freight is counted. Both a wholesale licence "
        "and a retail permit are required. No statewide flavour restriction "
        "is recorded."
    ),

    "Idaho": (
        "Idaho charges no state excise tax on e-cigarettes, so price here is "
        "largely a matter of freight. Delivery sales require age "
        "verification by credit or debit card confirmation of the buyer's "
        "name and age, and every shipment must carry a statement about the "
        "prohibition on shipping to anyone under 21. Licences are required "
        "for retail and, separately, for delivery sales (Idaho Code §§ "
        "39-5704(1), 39-5718(1)) -- that second licence is the likelier "
        "reason a shop declines Idaho. Nine retailers here exclude the "
        "state, all of them hemp sellers."
    ),

    "Illinois": (
        "Illinois has the most detailed delivery-sale regime of any state in "
        "this directory. Remote sales require third-party age verification. "
        "Delivery sales require the carrier to verify the buyer's age "
        "against government identification and to obtain a signature on a "
        "written certification; the seller must separately obtain that "
        "written certification, make a good-faith effort to verify age, and "
        "take payment by a card, cheque or written instrument in the "
        "buyer's own name. Tax is 15% of wholesale price (35 Ill. Comp. "
        "Stat. 143/10-10(a)). No statewide flavour restriction is recorded."
    ),

    "Indiana": (
        "Indiana taxes consumable material and vapor products at 15% of the "
        "retail dealer's gross retail income, with closed-system cartridges "
        "taxed at 15% of wholesale (Ind. Code § 6-7-4-9(b)). Delivery sales "
        "of e-liquid must be prepaid by the buyer, shipped only to the "
        "buyer, and made only after a good-faith effort to establish age, "
        "and the shipping documents must carry a stated warning that Indiana "
        "law prohibits sale to anyone under 21. Retail requires a licence. "
        "No statewide flavour restriction is recorded."
    ),

    "Iowa": (
        "Iowa charges no state excise tax on vapor products. Its one "
        "delivery requirement is straightforward and worth planning around: "
        "a retailer making a delivery sale must use a method that requires "
        "the signature of someone at least 21 before the package is "
        "released, so a parcel will not be left at the door. Retail and "
        "related licences are required (Iowa Code §§ 453A.47A(1), "
        "453A.13(1)). No statewide flavour restriction is recorded, and the "
        "six shops that decline Iowa are all hemp sellers acting on their "
        "own policies."
    ),

    "Kansas": (
        "Kansas applies a privilege tax of $0.05 per millilitre of "
        "consumable material (Kan. Stat. Ann. § 79-3399(a)) -- one of the "
        "lower rates, and charged by volume rather than as a percentage, so "
        "it bites hardest on large bottles and barely at all on a pod. "
        "Retailers and vending machine distributors both need licences "
        "(Kan. Stat. Ann. § 79-3303(a)). The source records no statewide "
        "flavour restriction and no special delivery-sale machinery beyond "
        "the age floor of 21, which makes Kansas one of the simpler states "
        "to order into."
    ),

    "Kentucky": (
        "Kentucky taxes closed vapor cartridges at $1.50 each and open "
        "systems at 15% of the distributor's actual selling price (Ky. Rev. "
        "Stat. Ann. § 138.140(2)(a)(4)) -- a flat per-cartridge charge that "
        "falls heavily on cheap pods and lightly on expensive ones. Retail "
        "licensing takes effect on 1 January 2026 under Ky. Stat. Ann. §§ "
        "438.305–438.350, which is new enough that some out-of-state "
        "sellers may still be catching up with it. No statewide flavour "
        "restriction is recorded."
    ),

    "Louisiana": (
        "Louisiana puts its online rule on the manufacturer: for online "
        "sales, age must be verified through a real-time electronic age "
        "verification platform, and verified again at the point of "
        "delivery. Two checks, not one. Tax is $0.15 per millilitre of "
        "consumable nicotine liquid, and licences cover retail, vending and "
        "wholesale (La. Stat. Ann. § 26:902). No statewide flavour "
        "restriction is recorded. Five shops in this directory decline "
        "Louisiana, and one of those limits only particular product lines "
        "rather than the state."
    ),

    "Maine": (
        "Maine taxes e-cigarettes as other tobacco products at 43% of "
        "wholesale price (Me. Stat. tit. 36, § 4403(2)), which is high "
        "enough to notice on a large order. The age floor is 21, subject to "
        "a grandfather clause for anyone who had turned 18 by 1 July 2018, "
        "and staff rules are unusually specific: an employee aged between 17 "
        "and 21 may only sell e-cigarettes with a supervisor of at least 21 "
        "present. A retail licence is required (Me. Stat. tit. 22, § "
        "1551-A(1)). No statewide flavour restriction is recorded."
    ),

    "Maryland": (
        "Maryland prohibits the sale of flavoured electronic smoking "
        "devices, disposables included, leaving only tobacco and menthol. "
        "That is the rule to know before ordering: the catalogue a shop "
        "shows you is wider than what it may lawfully send to a Maryland "
        "address. Tax runs at 20% of retail price on devices and 60% of "
        "retail on vaping liquid in containers of 5 ml or less (Md. Code "
        "Ann., Tax Gen. § 11–104(j)(2)). A licence is required to ship into "
        "or sell within the state, which is why some retailers skip "
        "Maryland altogether."
    ),

    "Massachusetts": (
        "Massachusetts restricts flavoured e-cigarettes and flavour "
        "enhancers to smoking bars for consumption on site -- with one "
        "exception that matters here, for sales of flavoured devices to "
        "out-of-state purchasers. Mail-order and internet sales are "
        "prohibited unless the retailer requires age verification and a "
        "signature from someone of legal age on receipt. Tax is 75% of "
        "wholesale price (Mass. Gen. Laws ch. 64C, § 7E(b)), among the "
        "steepest in the country. Only two shops in this directory decline "
        "Massachusetts, but what they can lawfully sell you here is "
        "narrower than elsewhere."
    ),

    "Michigan": (
        "Michigan is one of the few states whose statute addresses buying "
        "online head-on, and permits it: internet sales of vapor products "
        "are allowed once the buyer is verified as at least 21 through a "
        "third-party service. That single check is the whole of it. Behind "
        "that, Michigan charges no state excise tax on vapor products and "
        "requires no retail licence, so very little is added to a price "
        "between the wholesaler and your door. Three hemp retailers decline "
        "the state on their own policies, not on anything in law."
    ),

    "Minnesota": (
        "Minnesota taxes tobacco products including e-cigarettes at 95% of "
        "wholesale price (Minn. Stat. § 297F.05, subdiv. 3) -- the highest "
        "rate in this directory, and close to doubling the wholesale cost "
        "before anyone's margin. If a Minnesota price looks wrong, that is "
        "why. An out-of-state retailer making delivery sales after 31 "
        "December 2021 must file a statement with the Department of "
        "Revenue, a registration step some sellers decline to take. "
        "Licensing is handled locally, by town board or county board. No "
        "statewide flavour restriction is recorded."
    ),

    "Mississippi": (
        "Mississippi charges no state excise tax on vapor products and "
        "requires no retail licence, which makes it one of the cheapest and "
        "simplest states in this directory to buy into. Internet sales of "
        "alternative nicotine products, cartridges or components require "
        "third-party age verification -- one check, and the only "
        "state-specific hurdle recorded. No statewide flavour restriction "
        "applies. The four shops that decline Mississippi are hemp "
        "retailers following their own policies, and one of them restricts "
        "only part of its range."
    ),

    "Missouri": (
        "Missouri charges no state excise tax on vapor products. A retail "
        "licence is required (Mo. Rev. Stat. § 407.934.1), and no statewide "
        "flavour restriction is recorded. One figure needs explaining. The "
        "age written into Missouri's vapor statute is 18, which has been "
        "overtaken: 21 became the federal minimum for all tobacco products "
        "in December 2019, e-cigarettes among them, and no retailer will "
        "sell to an eighteen-year-old on the strength of the state text. "
        "Every shop in this directory ships to Missouri."
    ),

    "Montana": (
        "Montana charges no state excise tax on vapor products, and the "
        "source records no flavour restriction and no delivery-sale rules "
        "beyond the general age prohibition. A retail licence is required "
        "(Mont. Code Ann. § 16-11-303(1)). Montana is one of four "
        "destinations on this site whose statutory age, 18, predates the "
        "federal move to 21 in December 2019; that federal minimum covers "
        "every tobacco product and is what you will be asked to prove. Six "
        "hemp retailers decline Montana by their own choice."
    ),

    "Nebraska": (
        "This is the most important thing on the page: Nebraska prohibits "
        "delivery sales of electronic nicotine delivery systems by mail, "
        "internet or telephone to any Nebraska consumer. Read plainly, "
        "that means direct-to-consumer vape mail is barred here, whatever "
        "any retailer's shipping page says about reaching the state. The "
        "listing below counts shops that state they will post to Nebraska; "
        "it is not a statement that such a sale is lawful. Tax runs at "
        "$0.05 per millilitre up to 3 ml and 10% of wholesale above it, and "
        "a licence is required (Neb. Rev. Stat. § 28-1420). Worth checking "
        "the current position before ordering."
    ),

    "Nevada": (
        "Nevada allows sales over a computer, telephonic or other "
        "electronic network, but requires the parcel to be packed and "
        "clearly marked \"vapor products\" and the buyer's age verified "
        "through a third-party service first. Tax is 30% of wholesale price "
        "(Nev. Rev. Stat. § 370.450(1)), and both retail and wholesale "
        "licences are required. No statewide flavour restriction is "
        "recorded. Seven shops here decline Nevada, six of them hemp "
        "sellers, so the nicotine side of the market is close to fully "
        "available."
    ),

    "New Hampshire": (
        "New Hampshire taxes closed cartridges at $0.30 per millilitre and "
        "open containers at 8% of wholesale price -- a split that makes "
        "refillable kit markedly cheaper here than disposables, which is "
        "worth knowing before you choose a format. Retail, manufacture and "
        "wholesale licences are required (N.H. Rev. Stat. Ann. §§ 178:1(VI), "
        "78:6). No statewide flavour restriction and no special "
        "delivery-sale regime is recorded, so the age check at 21 should be "
        "the only hurdle at checkout."
    ),

    "New Jersey": (
        "New Jersey prohibits discounted sales and distribution of flavoured "
        "vapor products. Note what that does and does not say: it restricts "
        "discounting them, not selling them, which is a different rule from "
        "the outright flavour bans in New York and Rhode Island next door, "
        "and it is why a coupon may be refused here on a product that is "
        "itself available. Liquid nicotine is taxed at $0.10 per fluid "
        "millilitre, with container e-liquid treated separately (N.J. Stat. "
        "Ann. § 54:40B-3.2(a)). A licence is required for container "
        "e-liquid sales."
    ),

    "New Mexico": (
        "New Mexico taxes e-liquid at 12.5% of product value and closed "
        "cartridges at $0.50 each (N.M. Stat. Ann. § 7-12A-3(D), (E)) -- a "
        "flat charge per cartridge that falls hardest on cheap pods. "
        "Delivery sales require third-party age verification, which is the "
        "one extra step to expect at checkout. Manufacturers, distributors "
        "and retailers all require licences (N.M. Stat. Ann. §§ 61-37-5(A), "
        "61-37-6(A), 61-37-7(A)). No statewide flavour restriction is "
        "recorded, and every shop in this directory ships here."
    ),

    "New York": (
        "New York prohibits the sale of flavoured vapor products intended "
        "for nicotine consumption, so tobacco flavour is the practical "
        "limit on a New York address. Dealers are also prohibited from "
        "shipping nicotine vapor products to unauthorised persons in the "
        "state, and any shipment not in the manufacturer's original "
        "packaging must be clearly labelled \"vapor product\". Tax is 20% of "
        "retail price (N.Y. Tax Law § 1181) and a certificate of "
        "registration is required (N.Y. Tax Law § 1183). Nine shops here "
        "decline New York outright."
    ),

    "North Carolina": (
        "North Carolina taxes vapor products at $0.05 per fluid millilitre "
        "of consumable product (N.C. Gen. Stat. § 105-113.36A(a)(1)), one "
        "of the lower rates in the country. Internet distribution requires "
        "third-party age verification. The licensing detail worth noting is "
        "that the state licenses remote sellers specifically (N.C. Gen. "
        "Stat. § 105-113.38A(1)) as well as wholesale and retail, so a shop "
        "posting into North Carolina needs its own registration here. No "
        "statewide flavour restriction is recorded, and every shop in this "
        "directory ships to the state."
    ),

    "North Dakota": (
        "North Dakota charges no state excise tax on e-cigarettes. A "
        "retailer's sale and shipment of electronic smoking devices through "
        "the mail is prohibited unless the retailer verifies the buyer is at "
        "least 21 and requires a signature on delivery (N.D. Cent. Code § "
        "51-32-01(2)) -- so expect a parcel that cannot be left unattended. "
        "Distributors and retailers both require licences (N.D. Cent. Code § "
        "57-36-02(1)). There is a flavour provision, but it restricts sale "
        "to under-21s rather than restricting flavours generally."
    ),

    "Ohio": (
        "Ohio's tax is unusual in form: $0.01 per unit of vapor volume "
        "rather than a percentage or a per-millilitre rate (Ohio Rev. Code "
        "Ann. § 5743.62(A)(4)), which keeps it modest on most orders. Sale "
        "or shipment of alternative nicotine products by internet or other "
        "remote means is prohibited unless age verification is obtained, so "
        "the check at checkout is the thing that makes the order lawful "
        "rather than an inconvenience. Retail, wholesale and manufacture "
        "licences are required. No statewide flavour restriction is "
        "recorded, and every shop here ships to Ohio."
    ),

    "Oklahoma": (
        "Everything Oklahoma restricts is about the shop floor rather than "
        "the post: free samples within 300 feet of a playground or school, "
        "self-service displays confined to adult-only premises, vending "
        "machines kept out of reach. None of it bears on a parcel sent to "
        "your door, and the source records no delivery-sale regime at all "
        "beyond the prohibition on selling to anyone under 21. There is no "
        "state excise tax on vapor products, no retail licence requirement "
        "and no statewide flavour rule, which makes this one of the "
        "least-encumbered states on the site."
    ),

    "Oregon": (
        "Worth knowing before you shop: Oregon restricts remote sales and "
        "shipment of inhalant delivery systems to distributors and "
        "retailers, and a delivery sale requires the seller to hold an "
        "Oregon distributor's licence, obtain the buyer's certification of "
        "age, and verify age through a third-party system. In practice that "
        "confines consumer vape mail to sellers licensed here. Tax is 65% "
        "of wholesale price (Or. Rev. Stat. § 323.505(2)(c)). Ten shops in "
        "this directory decline Oregon, which is the highest count outside "
        "Utah, California and Vermont -- and the licensing position is a "
        "likely part of why."
    ),

    "Pennsylvania": (
        "Pennsylvania taxes e-cigarettes at 40% of the purchase price "
        "charged to the retailer (72 Pa. Cons. Stat. § 8202-A(a.1)), high "
        "enough that Pennsylvania prices sit noticeably above neighbouring "
        "Ohio. A licence is required for sales and manufacture. The age "
        "floor is 21, with a carve-out at 18 for military members and "
        "veterans. No statewide flavour restriction and no special remote "
        "sales regime is recorded, so the single age check should be the "
        "only step between you and a delivery."
    ),

    "Rhode Island": (
        "Rhode Island prohibits the sale of flavoured electronic nicotine "
        "delivery systems, so tobacco flavour is the limit on a Rhode "
        "Island address. Delivery sales require a valid government "
        "identification and an attestation from the buyer that it is "
        "correct, and the product must be delivered to the address on that "
        "identification -- a work address will not do. Tax is $0.50 per "
        "millilitre on prefilled products and 10% of wholesale on "
        "everything else. Ten shops decline the state, making it one of the "
        "hardest in this directory to buy into."
    ),

    "South Carolina": (
        "South Carolina charges no state excise tax on electronic smoking "
        "devices and requires no retail licence, so prices here are among "
        "the least marked up in the country. Internet or remote sales "
        "require third-party age verification, and that is the only "
        "state-specific step recorded. No statewide flavour restriction "
        "applies. Just one shop in this directory declines South Carolina, "
        "and it is a hemp retailer acting on its own policy rather than on "
        "anything in state law."
    ),

    "South Dakota": (
        "South Dakota charges no state excise tax on vapor products and "
        "requires no retail licence. No statewide flavour restriction is "
        "recorded, and no delivery-sale machinery beyond the general "
        "prohibitions on sale to and purchase by anyone under 21 (S.D. "
        "Codified Laws § 34-46-2(1), (2)). The state's other restrictions "
        "govern physical premises -- self-service displays and free samples "
        "near schools -- rather than mail order. Four shops decline South "
        "Dakota, three of them nicotine retailers, which inverts the usual "
        "pattern here."
    ),

    "Tennessee": (
        "Tennessee's rule for mail order is specific and easy to miss: a "
        "distributor sending vapor products by mail must obtain an "
        "affirmative statement from the recipient that they are 21 or "
        "older. Tax, effective 1 July 2025, is 10% of wholesale cost on "
        "open systems and $0.07 per millilitre on closed devices (Tenn. "
        "Code Ann. § 67-4-1005). No retail licence is required and no "
        "statewide flavour restriction is recorded, which together with the "
        "modest tax makes Tennessee one of the easier states in this "
        "directory to buy into."
    ),

    "Texas": (
        "Texas requires any retailer making e-cigarette delivery sales to "
        "register with the state, verify the buyer is over 21 both at "
        "purchase and at delivery, include a notice about the prohibition "
        "on sales to minors, and file certain purchaser information "
        "(Tex. Health & Safety Code Ann. § 161.456). Two checks and a "
        "registration, which is why some sellers skip Texas despite its "
        "size. There is no state excise tax on vapor products and no "
        "statewide flavour restriction. The age floor is 21, with a "
        "carve-out from 18 for holders of a US or state military "
        "identification card."
    ),

    "Utah": (
        "Two rules matter here, and both are strict. Utah prohibits giving, "
        "distributing or selling a flavoured electronic cigarette product "
        "at all. And internet and other remote sales are restricted to "
        "\"licensed persons\" -- licensed distributors, manufacturers and "
        "retailers -- with retail sales to consumers otherwise required to "
        "be a face-to-face exchange, bar legally compliant remote sales. "
        "Taken together, consumer vape mail into Utah is tightly confined, "
        "whatever a retailer's shipping page says. Tax is 56% of the "
        "manufacturer's sales price, and both a state licence and a local "
        "health department permit are required."
    ),

    "Vermont": (
        "Vermont restricts mail order, phone order and internet sales of "
        "tobacco substitutes and nicotine-containing substances to sales to "
        "licensed wholesalers and retailers. In plain terms, that confines "
        "consumer vape mail into the state, and the count of shops below "
        "that say they will post here is not a statement that such a sale "
        "is lawful. Vermont also carries the second-highest tax in this "
        "directory at 92% of wholesale price (Vt. Stat. Ann. tit. 32, § "
        "7811(a)). A licence is required. Twelve shops decline Vermont "
        "outright."
    ),

    "Virginia": (
        "The rule to know before you shop: Virginia prohibits mail order "
        "and internet sales of nicotine vapor products direct to consumers. "
        "Three shops in this directory decline Virginia of their own "
        "accord, but this provision reaches considerably further than those "
        "three, and the listing below counts what retailers say about "
        "shipping rather than what the law permits. Tax is $0.066 per "
        "millilitre on closed-system pods and 20% of wholesale on "
        "open-system e-liquid (Va. Code Ann. § 58.1-1021.02), and a licence "
        "is required. Check the current position before ordering."
    ),

    "Washington": (
        "Washington prohibits delivery sales of vapor products unless the "
        "seller holds a valid delivery sale licence, verifies the buyer's "
        "age through a third-party database, and accepts payment only by a "
        "card in the buyer's own name. The licence is the operative part: it "
        "is a specific permission a seller either has or does not (Wash. "
        "Rev. Code § 70.345.030(1)(a)). Tax is $0.27 per millilitre of "
        "solution, falling to $0.09 for accessible containers over 5 ml, so "
        "larger bottles are taxed more lightly per millilitre. No statewide "
        "flavour restriction is recorded."
    ),

    "West Virginia": (
        "West Virginia taxes e-cigarette liquid at $0.075 per millilitre or "
        "fraction thereof (W. Va. Code § 11-17-4b(b)(1)) -- a low rate, "
        "though charged on any part of a millilitre. No retail licence is "
        "required as such, though retail dealers and wholesalers selling "
        "e-cigarette liquids need a business registration certificate. No "
        "statewide flavour restriction and no delivery-sale regime beyond "
        "the age prohibition at 21 is recorded (W. Va. Code § 16-9A-3), "
        "which makes this a simple state to order into."
    ),

    "Wisconsin": (
        "Wisconsin taxes vapor products at $0.05 per millilitre of liquid "
        "(Wis. Stat. § 139.76(1m)), among the lower rates, and requires a "
        "retail licence (Wis. Stat. § 134.65(1d)). No statewide flavour "
        "restriction and no mail-order regime is recorded. Read the age "
        "provision with care. Wisconsin's nicotine statute says 18, a "
        "number superseded in December 2019 when the federal minimum for "
        "buying any tobacco product became 21. Checkout will ask you to "
        "prove 21, and a shop that did otherwise would be breaking federal "
        "law rather than following state law."
    ),

    "Wyoming": (
        "Wyoming taxes electronic cigarettes and vapor material at 15% of "
        "the wholesale price when bought or imported by wholesalers, with a "
        "fallback if the tax was not paid at wholesale (Wyo. Stat. Ann. § "
        "39-18-104(g)). No retail licence is required. Remote sales require "
        "third-party age verification and the signature of someone at least "
        "21 on receipt, so a Wyoming parcel will not be left unattended. No "
        "statewide flavour restriction is recorded, and only one shop in "
        "this directory declines the state."
    ),
}
