"""
Intro copy for the 51 destination pages.

Why these exist: a destination page with no copy of its own is identical to
the other fifty bar the state name, so wp-merchant-archive.php holds it at
noindex on purpose. An intro is what earns the page its place in the index.

Why they are written out in full rather than generated from a template: 51
pages assembled from one sentence pattern with the numbers swapped are still
51 near-identical pages, which is the problem the noindex gate exists to
prevent. Filling the gate with templated text would switch off the check
without fixing what it was checking for. build_state_intros.py enforces
that with a shingle test -- two intros sharing more than six eight-word runs
fail the build.

What every claim here rests on: each state's own line in the merchant data.
"Nine of the shops we track will not deliver to Idaho" means nine merchants
name Idaho in their own published shipping policy, with the page it was read
from recorded in fact_source_url. Nothing here states what any state's law
is -- that is a separate sourcing job, and it belongs in the per-term
"Local rules / legal note" field once each one has a citation behind it.

The build script checks every count and every store name against the
merchant CSV before writing the import file, so a number that drifts out of
date fails the build rather than reaching a page. It has already caught four
wrong store names and eight miscounts in this file.
"""

# state -> intro paragraph. Plain text; the page runs it through wpautop.
INTROS = {

    "Alabama": (
        "Almost every online vape shop we track delivers to Alabama: 102 of "
        "them, covering disposables, pod kits, e-liquid, coils and nicotine "
        "pouches. The three that do not are all hemp retailers -- elyxr, "
        "Mellow Fellow and D8 Super Store -- each naming Alabama in its own "
        "published shipping policy. If you are buying nicotine, that "
        "exclusion list will not affect you. If you came for delta-8 "
        "gummies or THC-A flower it narrows the field, and the shops below "
        "that do serve Alabama are the ones worth your time. D8 Super "
        "Store's list is worth a closer read than most: it names kratom and "
        "amanita products specifically rather than the whole catalogue, so "
        "what is blocked depends on what is in your basket."
    ),

    "Alaska": (
        "Shipping to Alaska is the exception rather than the rule in a lot "
        "of retail, so it is worth saying plainly: 97 of the shops we track "
        "deliver here. Eight do not -- zar AirPouch, XTAR Technology, "
        "elyxr, Botany Farms, Edobles, Mellow Fellow, Smilyn Wellness and "
        "Binoid CBD. The two hardware names on that list are the "
        "instructive ones, because lithium cells and pressurised goods are "
        "what couriers refuse to fly, and that has nothing to do with vape "
        "rules. Expect longer transit than the lower 48, and check whether "
        "a free-shipping threshold actually covers Alaska before you build "
        "a basket around one: several shops advertise a nationwide minimum "
        "and then quietly carve the state out of it."
    ),

    "Arizona": (
        "99 of the online vape shops we track ship to Arizona. Six will "
        "not, and they split across both halves of this market: CBD.co, "
        "Edobles, Mellow Fellow and Smilyn Wellness sell cannabinoids, "
        "while Pro vape and ECMVAPE are nicotine retailers. That second "
        "pair matters more than two names suggest, because hardware and "
        "e-liquid stores rarely exclude a whole state -- when one does it "
        "is usually a carrier or compliance decision rather than anything "
        "about the product. Everything else in the directory below takes "
        "Arizona addresses."
    ),

    "Arkansas": (
        "Arkansas is one of the harder states to buy vape and hemp products "
        "into online. Ten of the shops we track exclude it: Juice Head, "
        "elyxr, Vape Juice Depot, Botany Farms, Pro vape, Edobles, VaporFi, "
        "Mellow Fellow, Smilyn Wellness and D8 Super Store. Four of those "
        "are mainstream nicotine retailers rather than cannabinoid sellers, "
        "which is unusual and makes Arkansas worth checking before you "
        "shop, not after -- several well-known names will carry a full "
        "basket all the way to the payment screen and reject the address "
        "there. The 95 shops that do deliver are the ones listed here."
    ),

    "California": (
        "California has the longest exclusion list of any state in this "
        "directory, and its shape is unusual. Twelve shops will not ship "
        "here, and seven of them sell nicotine rather than hemp: Juice "
        "Head, Vape.com, Vape Juice Depot, Vapor Empire, Pro vape, "
        "Flawless Vape Shop and ECMVAPE. CBD.co, Green Roads, Edobles, "
        "Smilyn Wellness and Binoid CBD make up the rest. For the "
        "country's largest market that is a genuine constraint, and it is "
        "why a California address is turned away at checkout more often "
        "than most. 93 shops do deliver."
    ),

    "Colorado": (
        "98 of the shops we track deliver to Colorado, and the seven that "
        "do not are hemp retailers without exception: elyxr, Dragon Hemp, "
        "Botany Farms, CBD.co, Edobles, Smilyn Wellness and Binoid CBD. Not "
        "one nicotine vape store in this directory excludes the state, so "
        "disposables, pod systems, e-liquid, coils and pouches are all open "
        "to you. Cannabinoid shopping is where the field thins out. Note "
        "that the same handful of hemp names recurs across Colorado, Idaho "
        "and Montana, which points to one regional compliance decision "
        "repeated rather than seven independent ones -- so if an order was "
        "refused here before, trying a different store from that same group "
        "is unlikely to help."
    ),

    "Connecticut": (
        "Connecticut is well served: 102 of the online vape shops we track "
        "deliver here, and only three decline -- CBD.co, Edobles and "
        "Smilyn Wellness, all cannabinoid sellers. No nicotine retailer in "
        "this directory excludes the state. All three also turn up in the "
        "exclusion lists of most of Connecticut's neighbours, so the "
        "pattern belongs to those retailers rather than to the state. One "
        "thing to plan for before you order: 24 of these shops state that a "
        "parcel needs an adult signature at the door, so it will not be "
        "left on the step and someone over 21 has to be home to take it. "
        "The store pages say which ones require it."
    ),

    "Delaware": (
        "99 shops in this directory deliver to Delaware. The six that do "
        "not are hemp and CBD retailers -- elyxr, Botany Farms, CBD.co, "
        "Edobles, Mellow Fellow and Smilyn Wellness -- which means every "
        "nicotine vape store we track will take a Delaware address. For "
        "disposables, pods, e-liquid and pouches the whole list is yours, "
        "so price and dispatch speed are the only things left to weigh. "
        "Where a shop's discount is shown as a confirmed code, we have "
        "checked it works; where it is shown as a standing offer, we have "
        "not, and saying so is more useful than pretending otherwise."
    ),

    "District of Columbia": (
        "Every one of the 105 US-shipping stores we track delivers to the "
        "District of Columbia. Not a single retailer here names Washington "
        "DC in an exclusion list, on either the nicotine or the hemp side, "
        "which puts the District among only nine destinations on this site "
        "with a clean sheet. Worth knowing if you have shopped from a "
        "Maryland or Virginia address before: those are tracked separately "
        "here, and each has exclusions the District does not, so a store "
        "that once refused you across the river may well deliver now."
    ),

    "Florida": (
        "Florida is close to a clean sweep. 104 of the 105 US-shipping "
        "stores we track deliver here, and the single exception is Diamond "
        "CBD -- a hemp retailer headquartered in the state that names "
        "Florida in its own shipping policy anyway. Everything else in the "
        "directory is open to a Florida address, nicotine and cannabinoid "
        "alike. With the field that wide, the question stops being who will "
        "ship and becomes which offer is real: 17 of these shops have a "
        "code we have confirmed, and 23 are listed with no active code "
        "because we could not confirm one."
    ),

    "Georgia": (
        "103 of the online vape shops we track deliver to Georgia, and both "
        "exceptions are nicotine retailers rather than hemp sellers: Vape "
        "Juice Depot and VaporFi. That reverses the usual pattern here, "
        "where exclusions almost always come from the cannabinoid side -- "
        "every CBD and delta-8 store in this directory ships to Georgia. So "
        "whichever half of the market you are shopping, the list is wide, "
        "and what separates these shops is the shipping cost rather than "
        "the shipping rules. Thresholds for free delivery range from modest "
        "to optimistic, and the store pages give each one."
    ),

    "Hawaii": (
        "99 of the shops we track ship to Hawaii, which is better than the "
        "island-surcharge reputation suggests. Six do not, and they divide "
        "evenly: zar AirPouch, XTAR Technology and Vape Juice Depot against "
        "elyxr, Diamond CBD and Smilyn Wellness. Battery-heavy orders are "
        "the most likely to be refused, so if you are buying mods or "
        "external cells, read the policy before you order rather than "
        "after. Transit runs long, and as with Alaska a nationwide "
        "free-shipping minimum frequently excludes Hawaii in the small "
        "print even where the banner does not say so."
    ),

    "Idaho": (
        "Idaho has the most one-sided exclusion list in this directory. "
        "Nine shops will not deliver here and every one of them sells "
        "cannabinoids: elyxr, Dragon Hemp, Botany Farms, Diamond CBD, Pure "
        "Hemp Botanicals, CBD.co, Edobles, Mellow Fellow and Smilyn "
        "Wellness. Not one nicotine vape store excludes Idaho. If you are "
        "after disposables, pod kits, e-liquid, coils or pouches, all 96 "
        "shops that ship here are open to you and nothing is off the table. "
        "If you came for CBD or delta-8, expect a much shorter list, and "
        "start from the store's policy page rather than its product pages."
    ),

    "Illinois": (
        "All 105 US-shipping stores in this directory deliver to Illinois. "
        "No retailer we track, nicotine or hemp, names the state in an "
        "exclusion list, which puts Illinois in the small group of "
        "destinations where nothing at all is closed to you. That turns the "
        "decision into one about the offer rather than the address. Of the "
        "shops listed here, 17 carry a discount code we have tested and "
        "confirmed; the rest are shown either as a standing deal or, for "
        "23 of them, as having no confirmed code at all. The label on each "
        "listing tells you which, so a dead code does not waste your time "
        "at checkout."
    ),

    "Indiana": (
        "103 of the shops we track deliver to Indiana. Two decline -- elyxr "
        "and D8 Super Store -- and both sell cannabinoids. D8 Super Store's "
        "exclusion is narrower than it looks: its policy names kratom and "
        "amanita muscaria products for Indiana rather than its whole range, "
        "which is the sort of distinction only the store's own page will "
        "give you. Every nicotine vape shop in this directory ships here, "
        "so the disposables, pods, e-liquid and coils side of the market is "
        "entirely open. Worth being clear about what a listing on this page "
        "does and does not promise: it means the shop states it delivers to "
        "Indiana addresses, not that everything in its catalogue is "
        "available here. Where a retailer restricts a product line instead "
        "of a state, that sits in the policy text and never on the product "
        "page, so a basket can still fail at checkout at a shop listed "
        "below."
    ),

    "Iowa": (
        "99 of the online vape shops we track ship to Iowa, and the six "
        "that do not sell hemp across the board: elyxr, Botany Farms, "
        "CBD.co, Edobles, Mellow Fellow and Smilyn Wellness. It is the same "
        "group that declines several of Iowa's neighbours. No nicotine "
        "store here excludes the state. Something that catches people out "
        "in the middle of the country: dispatch speed and transit time are "
        "different things, and a shop that ships same-day from a coastal "
        "warehouse can still take longer to arrive than one that takes two "
        "days to pack but sits closer."
    ),

    "Kansas": (
        "103 shops in this directory deliver to Kansas, and only CBD.co and "
        "Smilyn Wellness decline -- both hemp retailers. Every nicotine "
        "vape store we track ships here. With availability settled, the "
        "thing most worth checking is the returns window: 98 of these shops "
        "have a published returns or refund policy on file here, linked "
        "from the listing, and they vary from a fortnight to nothing at all "
        "on opened goods. Sealed-only returns are common across vape "
        "retail, so it is worth reading before you buy rather than when "
        "something arrives wrong."
    ),

    "Kentucky": (
        "103 of the shops we track deliver to Kentucky. The two that do not "
        "are ECMVAPE and Edobles, one from each half of the market, which "
        "leaves Kentucky unusually open for nicotine and cannabinoid "
        "shopping alike. With a field this wide, the practical question is "
        "how to reach a shop if something goes wrong: we hold a contact "
        "page for 97 of these retailers and a support email address for "
        "69, both linked from the listing, and a store with neither is "
        "worth treating more cautiously than its prices suggest."
    ),

    "Louisiana": (
        "100 of the online vape shops we track ship to Louisiana. Five do "
        "not: Juice Head on the nicotine side, with DIET SMOKE, CBD.co, "
        "Smilyn Wellness and D8 Super Store on the hemp side. D8 Super "
        "Store singles out amanita muscaria products for Louisiana "
        "specifically rather than its whole range -- a good reminder that "
        "an exclusion is not always all-or-nothing, and that the store's "
        "own page is the only place the distinction is written down. For "
        "disposables, pods, e-liquid and pouches the list here is "
        "near-complete."
    ),

    "Maine": (
        "Maine is one of the few states where every exclusion comes from "
        "the nicotine side of the market. Five shops decline -- Juice Head, "
        "Vape Juice Depot, Pro vape, ECMVAPE and VaporFi -- and all five "
        "are vape retailers. Not a single CBD or delta-8 store in this "
        "directory excludes Maine. So cannabinoid shoppers have the full "
        "list open to them, while anyone buying disposables or e-liquid "
        "should check the store page first: those five are recognisable "
        "names, and two of them are among the larger retailers here. 100 "
        "shops do deliver."
    ),

    "Maryland": (
        "104 of the 105 US-shipping stores we track deliver to Maryland, "
        "with VIVANT the only exception. That makes it one of the most open "
        "destinations on this site for nicotine vapes and hemp products "
        "both, and means the address is almost never what stops an order. "
        "What does stop orders here is age verification at checkout: most "
        "of these retailers run an age check against the billing name and "
        "address before the payment goes through, so a nickname or an old "
        "address will fail even where the shipping is fine."
    ),

    "Massachusetts": (
        "103 of the shops we track ship to Massachusetts, and the two that "
        "do not are VIVANT and Smilyn Wellness -- one hardware retailer, "
        "one hemp seller. Massachusetts has a reputation among online "
        "shoppers for being difficult, so the figure deserves stating "
        "plainly: nearly everything in this directory will deliver to a "
        "Massachusetts address. Because the field is so wide, compare the "
        "offer and the delivery terms rather than availability -- and note "
        "that exclusion lists are the part of a shipping policy that "
        "changes most often, which is why each listing carries the date its "
        "policy was last read."
    ),

    "Michigan": (
        "102 of the online vape shops we track deliver to Michigan, and the "
        "three that decline -- CBD.co, Edobles and Smilyn Wellness -- are "
        "cannabinoid retailers. No nicotine vape store in this directory "
        "excludes the state, so the whole list is available for "
        "disposables, pod systems, e-liquid, coils and pouches. For hemp "
        "the choice is slightly narrower but far from closed. If you have "
        "been refused here before, it is worth re-checking: these three "
        "publish the exclusion themselves, and the other 102 do not, so a "
        "rejection from one says nothing about the rest."
    ),

    "Minnesota": (
        "99 of the shops we track ship to Minnesota. Six do not: elyxr, "
        "Botany Farms, Diamond CBD, CBD.co and Smilyn Wellness on the hemp "
        "side, plus Vape Juice Depot on the nicotine side. Minnesota sits "
        "in the cluster of upper-midwest states where the same hemp names "
        "recur, so a cannabinoid order refused here has probably been "
        "refused for the same reason by its neighbours. For nicotine "
        "products the field is nearly complete, and 93 of the retailers "
        "listed have a shipping policy page recorded here that you can read "
        "before ordering."
    ),

    "Mississippi": (
        "101 of the online vape shops we track deliver to Mississippi, and "
        "the four that do not -- CBD.co, Edobles, Mellow Fellow and Smilyn "
        "Wellness -- are hemp retailers without exception. Every nicotine "
        "vape store in this directory ships to a Mississippi address, so "
        "disposables, pod kits, e-liquid, coils and nicotine pouches are "
        "fully available. One of those four draws its line at particular "
        "product lines rather than the whole catalogue, so read the "
        "exclusion note on the store page before assuming a cannabinoid "
        "order is impossible."
    ),

    "Missouri": (
        "All 105 US-shipping stores in this directory deliver to Missouri. "
        "No retailer we track names the state in an exclusion list, on "
        "either side of the market, putting Missouri among the nine "
        "destinations here with nothing closed off. Since availability is "
        "not the question, freshness is: shipping terms on this site were "
        "read from each retailer's own policy page, and 100 of the 111 "
        "shops have both that page and the date it was read recorded on "
        "their listing. Where a date looks old, the link next to it is the "
        "fastest way to check the current position yourself."
    ),

    "Montana": (
        "99 of the shops we track ship to Montana, and the six that decline "
        "are hemp and CBD sellers: elyxr, Botany Farms, CBD.co, Edobles, "
        "Mellow Fellow and Smilyn Wellness. No nicotine vape retailer in "
        "this directory excludes Montana. Availability is less likely to "
        "catch you out here than distance -- transit runs longer than the "
        "national average, and a handful of shops advertise a nationwide "
        "free-shipping minimum that does not in fact extend to Montana. "
        "Read the threshold on the store page rather than the banner on "
        "the homepage."
    ),

    "Nebraska": (
        "104 of the 105 US-shipping stores we track deliver to Nebraska, "
        "with Vape Juice Depot the only one that does not. Both halves of "
        "the market are effectively wide open: every hemp and CBD retailer "
        "in this directory ships here, as does every nicotine shop bar that "
        "one. The address, then, is almost never what blocks an order. What "
        "does is the payment stage -- a card whose billing address does not "
        "match the one you are shipping to is declined by a good number of "
        "these retailers as a fraud precaution, regardless of state."
    ),

    "Nevada": (
        "98 of the online vape shops we track ship to Nevada. Seven do not, "
        "and six of those sell cannabinoids: elyxr, Botany Farms, CBD.co, "
        "Edobles, Mellow Fellow and Smilyn Wellness, with Juice Head the "
        "single nicotine name among them. If you are buying disposables, "
        "pods, e-liquid or pouches, that leaves the list essentially "
        "intact. If you came for delta-8 or CBD the field is noticeably "
        "thinner, and the store's policy page is the thing to read first. "
        "Those lists do change, which is why each one here carries the date "
        "it was last checked."
    ),

    "New Hampshire": (
        "103 of the shops we track deliver to New Hampshire, and only elyxr "
        "and Smilyn Wellness decline -- both hemp retailers. Every nicotine "
        "vape store in this directory ships to a New Hampshire address. "
        "With so little closed off, the useful comparison is what each shop "
        "does when something goes wrong rather than what it charges: a "
        "published returns policy, a contact page that is not just a form, "
        "and a support address that reaches a person. All three are "
        "recorded on the listings below where the retailer publishes them."
    ),

    "New Jersey": (
        "New Jersey is another state where the exclusions come from the "
        "nicotine side rather than the hemp side. Three shops decline -- "
        "Vape Juice Depot, Pro vape and VIVANT -- and all three are vape "
        "and hardware retailers. Every CBD and delta-8 seller in this "
        "directory ships to New Jersey, and 102 shops deliver here in "
        "total. Because the list is so wide, it is worth weighing the "
        "discount against the delivery cost rather than reaching for the "
        "biggest percentage: a large code at a shop that charges for "
        "postage can easily come out behind a smaller one where delivery is "
        "free at a basket you were going to fill anyway."
    ),

    "New Mexico": (
        "All 105 US-shipping stores in this directory deliver to New "
        "Mexico. Not one retailer we track, nicotine or cannabinoid, names "
        "the state in an exclusion list, making it one of nine destinations "
        "here where nothing is off the table. The constraint in New Mexico "
        "is geography rather than policy: much of the state is rural "
        "delivery, transit is slower than the coastal averages these shops "
        "quote, and a few free-shipping thresholds advertised as nationwide "
        "turn out to have a surcharge attached. Both are on the store's own "
        "shipping page, linked from its listing."
    ),

    "New York": (
        "New York has one of the longer exclusion lists on this site: nine "
        "shops will not deliver here. Five sell hemp -- elyxr, Botany "
        "Farms, CBD.co, Edobles and Smilyn Wellness -- and four come from "
        "the nicotine and hardware side: zar AirPouch, Pro vape, VIVANT and "
        "VaporFi. A New York address is therefore among the more likely to "
        "be refused at the payment screen, so checking the store page "
        "first saves real time. 96 shops do ship here, and those are the "
        "ones listed below. The five hemp names are the same ones that "
        "decline much of the northeast, so a cannabinoid order that has "
        "failed here before will probably fail at the others in that group "
        "as well. The four nicotine names are the surprise, and the ones "
        "worth noting individually before you fill a basket."
    ),

    "North Carolina": (
        "Every one of the 105 US-shipping stores we track delivers to North "
        "Carolina. No retailer in this directory names the state in an "
        "exclusion list, on either side of the market. One detail is worth "
        "knowing, though, because it is easy to misread: one shop's policy "
        "excludes a single North Carolina county rather than the state. "
        "Restrictions are not always drawn at the state line -- counties "
        "and individual towns appear in these policies too -- so where an "
        "order matters, the retailer's own page is the authority rather "
        "than any summary of it, including this one."
    ),

    "North Dakota": (
        "99 of the shops we track ship to North Dakota. Six will not, all "
        "of them cannabinoid sellers: elyxr, Botany Farms, CBD.co, Edobles, "
        "Mellow Fellow and Smilyn Wellness -- the same group that turns "
        "down much of the upper midwest. Nicotine shoppers are unaffected "
        "either way. What costs money on this page is freight rather than "
        "policy: a number of these retailers quote one flat rate for the "
        "contiguous states and another for the sparser routes, and that gap "
        "is frequently wider than the discount code that brought you here."
    ),

    "Ohio": (
        "All 105 US-shipping stores in this directory deliver to Ohio, one "
        "of nine destinations on this site with no exclusions at all. "
        "Neither the nicotine vape shops nor the hemp sellers name the "
        "state in their policies. With the whole list available, the "
        "detail most likely to affect your order is who has to be home: 24 "
        "of these retailers state that deliveries require an adult "
        "signature, which means a weekday parcel to an empty house comes "
        "back to the depot rather than sitting on the porch. The listings "
        "say which ones."
    ),

    "Oklahoma": (
        "104 of the 105 US-shipping stores we track deliver to Oklahoma, "
        "with Smilyn Wellness the single exception. Every nicotine vape "
        "retailer in this directory ships here and all but one of the hemp "
        "sellers do too, so an Oklahoma address is almost never what stops "
        "an order. With that settled, the comparison worth making is "
        "between the discount and the shipping: these shops set their "
        "free-delivery thresholds anywhere from a small basket to one well "
        "past what most people buy at once, and the threshold is listed on "
        "each store page."
    ),

    "Oregon": (
        "Oregon has one of the three longest exclusion lists in this "
        "directory. Ten shops will not deliver here: elyxr, Botany Farms, "
        "Diamond CBD, CBD.co, Green Roads, Edobles, Smilyn Wellness and "
        "Binoid CBD on the hemp side, with Pro vape and VaporFi on the "
        "nicotine side. For cannabinoid shopping in particular that is a "
        "substantial dent in the field, and an Oregon address is among the "
        "most likely here to be turned away. The 95 shops that do ship are "
        "the ones listed, and each links the policy page its terms came "
        "from so you can confirm before ordering."
    ),

    "Pennsylvania": (
        "Pennsylvania is one of nine destinations on this site that no "
        "retailer excludes. All 105 US-shipping stores we track will "
        "deliver here, hemp and nicotine alike, so there is nothing to "
        "rule out before you start. That moves the useful question to what "
        "happens after you buy: 98 of these shops "
        "have a published returns or refund policy recorded here and "
        "linked from the listing. Vape retail leans heavily on "
        "sealed-only returns, and the shops that say so plainly are easier "
        "to deal with than the ones that leave it unstated."
    ),

    "Rhode Island": (
        "Rhode Island is among the hardest states in this directory to buy "
        "into. Ten shops exclude it: elyxr, Vape Juice Depot, Botany "
        "Farms, Diamond CBD, CBD.co, VIVANT, Edobles, Mellow Fellow, "
        "Smilyn Wellness and D8 Super Store. For a state this small that is "
        "a disproportionate number, and it makes a Rhode Island address one "
        "of the most likely on this site to be refused at checkout. The 95 "
        "shops that do deliver are listed here, each with the shipping page "
        "its terms were read from, so you can confirm the position still "
        "holds before you order."
    ),

    "South Carolina": (
        "104 of the 105 US-shipping stores we track deliver to South "
        "Carolina, with Botany Farms the only exception. Every nicotine "
        "vape shop in this directory ships here, as does every hemp "
        "retailer bar that one, so availability is rarely the constraint. "
        "A more useful filter is whether anyone has vouched for the shop: "
        "11 of these retailers link a review profile from their own site, "
        "recorded on the listing where they do. A shop that publishes its "
        "own reviews page is making a claim it can be held to, which is "
        "worth more than a rating we could not verify."
    ),

    "South Dakota": (
        "101 of the online vape shops we track ship to South Dakota. Four "
        "do not, and three of them are nicotine retailers rather than hemp "
        "sellers: Vape Juice Depot, Pro vape and VaporFi, with elyxr the "
        "one cannabinoid name. That inverts the usual pattern, so if you "
        "are buying disposables or e-liquid it is worth checking the store "
        "page first; if you came for CBD or delta-8 the field is close to "
        "complete. Rural transit is the other thing to budget for here, "
        "both in days and in what the shipping actually costs."
    ),

    "Tennessee": (
        "All 105 US-shipping stores in this directory deliver to Tennessee. "
        "No retailer we track excludes the state, on either the nicotine or "
        "the hemp side, which gives Tennessee a completely clean sheet -- "
        "one of nine such destinations here. With nothing to rule out, the "
        "thing worth checking is how reachable each shop is before you "
        "need it to be: 97 of these retailers publish a contact page and "
        "69 publish a support email address, both recorded on the listing. "
        "A store that lists neither is harder to chase when a parcel goes "
        "missing."
    ),

    "Texas": (
        "All 105 US-shipping stores in this directory deliver to Texas -- "
        "the largest market on this site, and one of only nine "
        "destinations where no retailer we track names an exclusion. Both "
        "halves of the market are fully open: every nicotine vape shop and "
        "every hemp and CBD seller will take a Texas address. That makes "
        "this a pure comparison on the offer. Of the shops here, 17 have a "
        "discount code we have confirmed works, and 23 are shown with no "
        "active code at all rather than a guess dressed up as one."
    ),

    "Utah": (
        "Utah has the longest exclusion list on this site, tied with "
        "California and Vermont. Twelve shops will not deliver here. Five "
        "sell nicotine -- Juice Head, Vape Juice Depot, Pro vape, ECMVAPE "
        "and VaporFi -- and seven sell cannabinoids: elyxr, Botany Farms, "
        "Diamond CBD, CBD.co, Edobles, Mellow Fellow and Smilyn Wellness. "
        "Both halves of the market being hit is unusual, and it makes a "
        "Utah address among the most likely on this site to be refused. 93 "
        "shops do ship here -- and Utah, California and Vermont are the "
        "three lowest counts in this directory, all three sitting at that "
        "same figure. Every one of those twelve exclusions comes from the "
        "retailer's own published policy rather than a report of one, and "
        "each listing links the page it was read from with the date, "
        "because this is the part of a shipping policy that moves."
    ),

    "Vermont": (
        "Vermont shares the longest exclusion list in this directory with "
        "Utah and California. Twelve shops decline: Juice Head, Vape Juice "
        "Depot, Pro vape, ECMVAPE and VaporFi from the nicotine side, and "
        "elyxr, Botany Farms, Diamond CBD, CBD.co, Edobles, Smilyn Wellness "
        "and D8 Super Store from the hemp side. For a state of Vermont's "
        "size that is a striking figure, and it means reading the store "
        "page before you shop saves more time here than almost anywhere "
        "else. 93 shops do deliver, and they are the ones listed."
    ),

    "Virginia": (
        "102 of the online vape shops we track deliver to Virginia. Three "
        "do not: Mellow Fellow and Smilyn Wellness on the hemp side, and "
        "Vape Juice Depot on the nicotine side. That leaves the list wide "
        "open on both counts, so availability is unlikely to decide your "
        "order. If you have shopped from a DC or Maryland address before, "
        "note that those are tracked as separate destinations here with "
        "different exclusion lists, so what a store did for one tells you "
        "nothing reliable about another. All three Virginia exclusions are "
        "ones the retailer publishes itself, which is the only reason they "
        "appear here: where a shop says nothing about a state, this site "
        "treats it as shipping there and says so, rather than implying a "
        "check that was never made."
    ),

    "Washington": (
        "Seven shops decline Washington, and every one of them sells "
        "cannabinoids: elyxr, CBD.co, Tillmans Tranquils, Edobles, Mellow "
        "Fellow, Smilyn Wellness and Binoid CBD. 98 deliver here, which "
        "leaves the nicotine half of this market completely untouched -- "
        "not a single vape, e-liquid or pouch retailer on the site rules "
        "Washington out. For hemp shoppers the real list is shorter than "
        "the headline figure implies, and the place to settle it is the "
        "retailer's shipping policy rather than its catalogue: a product "
        "page almost never mentions a state exclusion even when the policy "
        "behind it spells one out."
    ),

    "West Virginia": (
        "Two hemp retailers, Smilyn Wellness and Binoid CBD, are the only "
        "shops on this site that will not deliver to West Virginia. The "
        "other 103 will, cannabinoid and nicotine alike, which leaves "
        "almost nothing to rule out before you start. The variable here is "
        "the road rather than the policy. Mountain routes add days that "
        "coastal dispatch estimates do not account for, and most of these "
        "store pages quote handling time and transit time separately -- it "
        "is the second number that decides when a parcel actually lands, "
        "and the one more often left out of the headline promise."
    ),

    "Wisconsin": (
        "103 of the online vape shops we track deliver to Wisconsin. The "
        "two that do not are elyxr and D8 Super Store, both cannabinoid "
        "retailers -- and D8 Super Store's exclusion covers kratom "
        "specifically rather than its whole catalogue, the sort of detail "
        "only the store's own policy spells out. Every nicotine vape shop "
        "in this directory ships to Wisconsin, so that half of the market "
        "is entirely open. The same two names recur in Indiana and "
        "Alabama, which suggests one policy decision applied across "
        "several states."
    ),

    "Wyoming": (
        "104 of the 105 US-shipping stores we track deliver to Wyoming, "
        "with Dragon Hemp the only exception. Both the nicotine and the "
        "hemp side are effectively fully open, so a Wyoming address is "
        "almost never the thing that stops an order. The constraint here is "
        "logistics rather than policy: transit runs longer than the "
        "national average, and a free-shipping minimum advertised as "
        "nationwide does not always extend this far. Read the threshold on "
        "the store page rather than the homepage banner, where the carve-out "
        "is usually left out."
    ),
}
